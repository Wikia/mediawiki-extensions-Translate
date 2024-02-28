<?php
/**
 * Contains classes for handling the message index.
 *
 * @file
 * @author Niklas Laxstrom
 * @copyright Copyright © 2008-2013, Niklas Laxström
 * @license GPL-2.0-or-later
 */

use MediaWiki\Extension\Translate\HookRunner;
use MediaWiki\Extension\Translate\MessageGroupProcessing\MessageGroups;
use MediaWiki\Extension\Translate\Services;
use MediaWiki\Extension\Translate\Statistics\RebuildMessageGroupStatsJob;
use MediaWiki\Extension\Translate\Utilities\Utilities;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;

/**
 * Creates a database of keys in all groups, so that namespace and key can be
 * used to get the groups they belong to. This is used as a fallback when
 * loadgroup parameter is not provided in the request, which happens if someone
 * reaches a messages from somewhere else than Special:Translate. Also used
 * by Special:TranslationStats and alike which need to map lots of titles
 * to message groups.
 */
abstract class MessageIndex {
	private const CACHEKEY = 'Translate-MessageIndex-interim';

	private const READ_LATEST = true;

	/** @var self */
	protected static $instance;
	/** @var MapCacheLRU|null */
	private static $keysCache;
	/** @var BagOStuff */
	protected $interimCache;
	/** @var WANObjectCache */
	private $statusCache;
	/** @var JobQueueGroup */
	private $jobQueueGroup;
	/** @var HookRunner */
	private $hookRunner;
	private LoggerInterface $logger;

	public function __construct() {
		// TODO: Use dependency injection
		$mwInstance = MediaWikiServices::getInstance();
		$this->statusCache = $mwInstance->getMainWANObjectCache();
		$this->jobQueueGroup = $mwInstance->getJobQueueGroup();
		$this->hookRunner = Services::getInstance()->getHookRunner();
		$this->logger = LoggerFactory::getInstance( 'Translate' );
		$this->interimCache = ObjectCache::getInstance( CACHE_ANYTHING );
	}

	/**
	 * @deprecated Since 2020.10 Use Services::getMessageIndex()
	 * @return self
	 */
	public static function singleton(): self {
		if ( self::$instance === null ) {
			self::$instance = Services::getInstance()->getMessageIndex();
		}

		return self::$instance;
	}

	/**
	 * Override the global instance, for testing.
	 *
	 * @since 2015.04
	 * @param MessageIndex $instance
	 */
	public static function setInstance( self $instance ) {
		self::$instance = $instance;
	}

	/**
	 * Retrieves a list of groups given MessageHandle belongs to.
	 * @since 2012-01-04
	 * @param MessageHandle $handle
	 * @return string[]
	 */
	public static function getGroupIds( MessageHandle $handle ): array {
		global $wgTranslateMessageNamespaces;

		$title = $handle->getTitle();

		if ( !$title->inNamespaces( $wgTranslateMessageNamespaces ) ) {
			return [];
		}

		$namespace = $title->getNamespace();
		$key = $handle->getKey();
		$normkey = Utilities::normaliseKey( $namespace, $key );

		$cache = self::getCache();
		$value = $cache->get( $normkey );
		if ( $value === null ) {
			$value = (array)self::singleton()->getWithCache( $normkey );
			$cache->set( $normkey, $value );
		}

		return $value;
	}

	/** @return MapCacheLRU */
	private static function getCache() {
		if ( self::$keysCache === null ) {
			self::$keysCache = new MapCacheLRU( 30 );
		}
		return self::$keysCache;
	}

	/**
	 * @since 2012-01-04
	 * @param MessageHandle $handle
	 * @return ?string
	 */
	public static function getPrimaryGroupId( MessageHandle $handle ): ?string {
		$groups = self::getGroupIds( $handle );

		return count( $groups ) ? array_shift( $groups ) : null;
	}

	private function getWithCache( $key ) {
		$interimCacheValue = $this->getInterimCache()->get( self::CACHEKEY );
		if ( $interimCacheValue && isset( $interimCacheValue['newKeys'][$key] ) ) {
			$this->logger->debug(
				'[MessageIndex] interim cache hit: {messageKey} with value {groupId}',
				[ 'messageKey' => $key, 'groupId' => $interimCacheValue['newKeys'][$key] ]
			);
			return $interimCacheValue['newKeys'][$key];
		}

		return $this->get( $key );
	}

	/**
	 * Looks up the stored value for single key. Only for testing.
	 * @since 2012-04-10
	 * @param string $key
	 * @return string|array|null
	 */
	protected function get( $key ) {
		// Default implementation
		$mi = $this->retrieve();
		return $mi[$key] ?? null;
	}

	abstract public function retrieve( bool $readLatest = false ): array;

	/**
	 * @since 2018.01
	 * @return string[]
	 */
	public function getKeys() {
		return array_keys( $this->retrieve() );
	}

	abstract protected function store( array $array, array $diff );

	protected function lock() {
		return true;
	}

	protected function unlock() {
		return true;
	}

	/**
	 * Creates the index from scratch.
	 *
	 * @param float|null $timestamp Purge interim caches older than this timestamp.
	 * @return array
	 * @throws Exception
	 */
	public function rebuild( float $timestamp = null ): array {
		static $recursion = 0;

		if ( $recursion > 0 ) {
			$msg = __METHOD__ . ': trying to recurse - building the index first time?';
			wfWarn( $msg );

			$recursion--;
			return [];
		}
		$recursion++;

		$this->logger->info(
			'[MessageIndex] Started rebuild. Initiated by {callers}',
			[ 'callers' => wfGetAllCallers( 20 ) ]
		);

		$groups = MessageGroups::singleton()->getGroups();

		$tsStart = microtime( true );
		if ( !$this->lock() ) {
			throw new MessageIndexException( __CLASS__ . ': unable to acquire lock' );
		}

		$lockWaitDuration = microtime( true ) - $tsStart;
		$this->logger->info(
			'[MessageIndex] Got lock in {duration}',
			[ 'duration' => $lockWaitDuration ]
		);

		self::getCache()->clear();

		$new = [];
		$old = $this->retrieve( self::READ_LATEST );
		$postponed = [];

		/** @var MessageGroup $g */
		foreach ( $groups as $g ) {
			if ( !$g->exists() ) {
				$id = $g->getId();
				wfWarn( __METHOD__ . ": group '$id' is registered but does not exist" );
				continue;
			}

			# Skip meta thingies
			if ( $g->isMeta() ) {
				$postponed[] = $g;
				continue;
			}

			$this->checkAndAdd( $new, $g );
		}

		foreach ( $postponed as $g ) {
			$this->checkAndAdd( $new, $g, true );
		}

		$diff = self::getArrayDiff( $old, $new );
		$this->store( $new, $diff['keys'] );
		$this->unlock();

		$criticalSectionDuration = microtime( true ) - $tsStart - $lockWaitDuration;
		$this->logger->info(
			'[MessageIndex] Finished critical section in {duration}',
			[ 'duration' => $criticalSectionDuration ]
		);

		$cache = $this->getInterimCache();
		$interimCacheValue = $cache->get( self::CACHEKEY );
		if ( $interimCacheValue ) {
			$timestamp ??= microtime( true );
			if ( $interimCacheValue['timestamp'] <= $timestamp ) {
				$cache->delete( self::CACHEKEY );
				$this->logger->debug(
					'[MessageIndex] Deleted interim cache with timestamp {cacheTimestamp} <= {currentTimestamp}.',
					[
						'cacheTimestamp' => $interimCacheValue['timestamp'],
						'currentTimestamp' => $timestamp,
					]
				);
			} else {
				// Cache has a later timestamp. This may be caused due to
				// job deduplication. Just in case, spin off a new job to clean up the cache.
				$job = MessageIndexRebuildJob::newJob( __METHOD__ );
				$this->jobQueueGroup->push( $job );
				$this->logger->debug(
					'[MessageIndex] Kept interim cache with timestamp {cacheTimestamp} > ${currentTimestamp}.',
					[
						'cacheTimestamp' => $interimCacheValue['timestamp'],
						'currentTimestamp' => $timestamp,
					]
				);
			}
		}

		// Other caches can check this key to know when they need to refresh
		$this->statusCache->touchCheckKey( $this->getStatusCacheKey() );

		$this->clearMessageGroupStats( $diff );

		$recursion--;

		return $new;
	}

	/**
	 * @since 2021.10
	 * @return string
	 */
	public function getStatusCacheKey(): string {
		return $this->statusCache->makeKey( 'Translate', 'MessageIndex', 'status' );
	}

	private function getInterimCache(): BagOStuff {
		return $this->interimCache;
	}

	public function storeInterim( MessageGroup $group, array $newKeys ): void {
		$namespace = $group->getNamespace();
		$id = $group->getId();

		$normalizedNewKeys = [];
		foreach ( $newKeys as $key ) {
			$normalizedNewKeys[Utilities::normaliseKey( $namespace, $key )] = $id;
		}

		$cache = $this->getInterimCache();
		// Merge with existing keys (if present)
		$interimCacheValue = $cache->get( self::CACHEKEY, $cache::READ_LATEST );
		if ( $interimCacheValue ) {
			$normalizedNewKeys = array_merge( $interimCacheValue['newKeys'], $normalizedNewKeys );
			$this->logger->debug(
				'[MessageIndex] interim cache: merging with existing cache of size {count}',
				[ 'count' => count( $interimCacheValue['newKeys'] ) ]
			);
		}

		$value = [
			'timestamp' => microtime( true ),
			'newKeys' => $normalizedNewKeys,
		];

		$cache->set( self::CACHEKEY, $value, $cache::TTL_DAY );
		$this->logger->debug(
			'[MessageIndex] interim cache: added group {groupId} with new size {count} keys and ' .
			'timestamp {cacheTimestamp}',
			[ 'groupId' => $id, 'count' => count( $normalizedNewKeys ), 'cacheTimestamp' => $value['timestamp'] ]
		);
	}

	/**
	 * Compares two associative arrays.
	 *
	 * Values must be a string or list of strings. Returns an array of added,
	 * deleted and modified keys as well as value changes (you can think values
	 * as categories and keys as pages). Each of the keys ('add', 'del', 'mod'
	 * respectively) maps to an array whose keys are the changed keys of the
	 * original arrays and values are lists where first element contains the
	 * old value and the second element the new value.
	 *
	 * @code
	 * $a = [ 'a' => '1', 'b' => '2', 'c' => '3' ];
	 * $b = [ 'b' => '2', 'c' => [ '3', '2' ], 'd' => '4' ];
	 *
	 * self::getArrayDiff( $a, $b ) === [
	 *   'keys' => [
	 *     'add' => [ 'd' => [ [], [ '4' ] ] ],
	 *     'del' => [ 'a' => [ [ '1' ], [] ] ],
	 *     'mod' => [ 'c' => [ [ '3' ], [ '3', '2' ] ] ],
	 *   ],
	 *   'values' => [ 2, 4, 1 ]
	 * ];
	 * @endcode
	 *
	 * @param array $old
	 * @param array $new
	 * @return array
	 */
	public static function getArrayDiff( array $old, array $new ) {
		$values = [];
		$record = static function ( $groups ) use ( &$values ) {
			foreach ( $groups as $group ) {
				$values[$group] = true;
			}
		};

		$keys = [
			'add' => [],
			'del' => [],
			'mod' => [],
		];

		foreach ( $new as $key => $groups ) {
			if ( !isset( $old[$key] ) ) {
				$keys['add'][$key] = [ [], (array)$groups ];
				$record( (array)$groups );
			// Using != here on purpose to ignore the order of items
			} elseif ( $groups != $old[$key] ) {
				$keys['mod'][$key] = [ (array)$old[$key], (array)$groups ];
				$record( array_diff( (array)$old[$key], (array)$groups ) );
				$record( array_diff( (array)$groups, (array)$old[$key] ) );
			}
		}

		foreach ( $old as $key => $groups ) {
			if ( !isset( $new[$key] ) ) {
				$keys['del'][$key] = [ (array)$groups, [] ];
				$record( (array)$groups );
			}
			// We already checked for diffs above
		}

		return [
			'keys' => $keys,
			'values' => array_keys( $values ),
		];
	}

	/**
	 * Purge stuff when set of keys have changed.
	 *
	 * @param array $diff
	 */
	protected function clearMessageGroupStats( array $diff ) {
		$job = RebuildMessageGroupStatsJob::newRefreshGroupsJob( $diff['values'] );
		$this->jobQueueGroup->push( $job );

		foreach ( $diff['keys'] as $keys ) {
			foreach ( $keys as $key => $data ) {
				[ $ns, $pagename ] = explode( ':', $key, 2 );
				$title = Title::makeTitle( (int)$ns, $pagename );
				$handle = new MessageHandle( $title );
				[ $oldGroups, $newGroups ] = $data;
				$this->hookRunner->onTranslateEventMessageMembershipChange(
					$handle, $oldGroups, $newGroups );
			}
		}
	}

	/**
	 * @param array &$hugearray
	 * @param MessageGroup $g
	 * @param bool $ignore
	 */
	protected function checkAndAdd( &$hugearray, MessageGroup $g, $ignore = false ) {
		$keys = $g->getKeys();
		$id = $g->getId();
		$namespace = $g->getNamespace();

		foreach ( $keys as $key ) {
			# Force all keys to lower case, because the case doesn't matter and it is
			# easier to do comparing when the case of first letter is unknown, because
			# mediawiki forces it to upper case
			$key = Utilities::normaliseKey( $namespace, $key );
			if ( isset( $hugearray[$key] ) ) {
				if ( !$ignore ) {
					$to = implode( ', ', (array)$hugearray[$key] );
					wfWarn( "Key $key already belongs to $to, conflict with $id" );
				}

				if ( is_array( $hugearray[$key] ) ) {
					// Hard work is already done, just add a new reference
					$hugearray[$key][] = & $id;
				} else {
					// Store the actual reference, then remove it from array, to not
					// replace the references value, but to store an array of new
					// references instead. References are hard!
					$value = & $hugearray[$key];
					unset( $hugearray[$key] );
					$hugearray[$key] = [ &$value, &$id ];
				}
			} else {
				$hugearray[$key] = & $id;
			}
		}
		unset( $id ); // Disconnect the previous references to this $id
	}

	/**
	 * These are probably slower than serialize and unserialize,
	 * but they are more space efficient because we only need
	 * strings and arrays.
	 * @param mixed $data
	 * @return mixed
	 */
	protected function serialize( $data ) {
		return is_array( $data ) ? implode( '|', $data ) : $data;
	}

	protected function unserialize( $data ) {
		$array = explode( '|', $data );
		return count( $array ) > 1 ? $array : $data;
	}
}
