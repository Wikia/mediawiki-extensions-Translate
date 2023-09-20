<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\Translate\Cache;

use ArrayIterator;
use Iterator;
use MediaWiki\Json\JsonCodec;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * A persistent cache implementation using the database.
 * @author Abijeet Patro
 * @license GPL-2.0-or-later
 * @since 2020.12
 */
class PersistentDatabaseCache implements PersistentCache {
	private const TABLE_NAME = 'translate_cache';

	/** @var ILoadBalancer */
	private $loadBalancer;
	/** @var JsonCodec */
	private $jsonCodec;

	public function __construct( ILoadBalancer $loadBalancer, JsonCodec $jsonCodec ) {
		$this->loadBalancer = $loadBalancer;
		$this->jsonCodec = $jsonCodec;
	}

	/** @return PersistentCacheEntry[] */
	public function get( string ...$keynames ): array {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'tc_key', 'tc_value', 'tc_exptime', 'tc_tag' ] )
			->from( self::TABLE_NAME )
			->where( [ 'tc_key' => $keynames ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		return $this->buildEntries( $rows );
	}

	public function getWithLock( string $keyname ): ?PersistentCacheEntry {
		$dbr = $this->loadBalancer->getConnection( DB_PRIMARY );
		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'tc_key', 'tc_value', 'tc_exptime', 'tc_tag' ] )
			->forUpdate()
			->from( self::TABLE_NAME )
			->where( [ 'tc_key' => $keyname ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$entries = $this->buildEntries( $rows );
		return count( $entries ) ? $entries[0] : null;
	}

	/** @return PersistentCacheEntry[] */
	public function getByTag( string $tag ): array {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'tc_key', 'tc_value', 'tc_exptime', 'tc_tag' ] )
			->from( self::TABLE_NAME )
			->where( [ 'tc_tag' => $tag ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		return $this->buildEntries( $rows );
	}

	public function has( string $keyname ): bool {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$hasRow = $dbr->newSelectQueryBuilder()
			->select( 'tc_key' )
			->from( self::TABLE_NAME )
			->where( [ 'tc_key' => $keyname ] )
			->caller( __METHOD__ )
			->fetchRow();

		return (bool)$hasRow;
	}

	public function hasEntryWithTag( string $tag ): bool {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$hasRow = $dbr->newSelectQueryBuilder()
			->select( 'tc_key' )
			->from( self::TABLE_NAME )
			->where( [ 'tc_tag' => $tag ] )
			->caller( __METHOD__ )
			->fetchRow();

		return (bool)$hasRow;
	}

	public function hasExpiredEntry( string $keyname ): bool {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$row = $dbr->newSelectQueryBuilder()
			->select( 'tc_expired' )
			->from( self::TABLE_NAME )
			->where( [ 'tc_key' => $keyname ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( $row === false ) {
			return false;
		}

		$rows = new ArrayIterator( [ $row ] );
		$entry = $this->buildEntries( $rows )[0];
		return $entry->hasExpired();
	}

	public function set( PersistentCacheEntry ...$entries ): void {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );

		foreach ( $entries as $entry ) {
			$value = $this->jsonCodec->serialize( $entry->value() );
			$rowsToInsert = [
				'tc_key' => $entry->key(),
				'tc_value' => $value,
				'tc_exptime' => $entry->exptime(),
				'tc_tag' => $entry->tag()
			];

			$rowsToUpdate = [
				'tc_value' => $value,
				'tc_exptime' => $entry->exptime(),
				'tc_tag' => $entry->tag()
			];

			$dbw->upsert(
				self::TABLE_NAME,
				$rowsToInsert,
				'tc_key',
				$rowsToUpdate,
				__METHOD__
			);
		}
	}

	public function setExpiry( string $keyname, int $expiryTime ): void {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$dbw->update(
			self::TABLE_NAME,
			[ 'tc_exptime' => $expiryTime ],
			[ 'tc_key' => $keyname ],
			__METHOD__
		);
	}

	public function delete( string ...$keynames ): void {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$dbw->delete(
			self::TABLE_NAME,
			[ 'tc_key' => $keynames ],
			 __METHOD__
		);
	}

	public function deleteEntriesWithTag( string $tag ): void {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$dbw->delete(
			self::TABLE_NAME,
			[ 'tc_tag' => $tag ],
			__METHOD__
		);
	}

	public function clear(): void {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$dbw->delete(
			self::TABLE_NAME,
			'*',
			__METHOD__
		);
	}

	/** @return PersistentCacheEntry[] */
	private function buildEntries( Iterator $rows ): array {
		$entries = [];
		foreach ( $rows as $row ) {
			$entries[] = new PersistentCacheEntry(
				$row->tc_key,
				$this->jsonCodec->unserialize( $row->tc_value ),
				$row->tc_exptime ? (int)$row->tc_exptime : null,
				$row->tc_tag
			);
		}

		return $entries;
	}
}
