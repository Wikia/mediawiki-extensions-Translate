<?php
declare( strict_types = 1 );

use MediaWiki\Extension\Translate\MessageGroupProcessing\MessageGroups;

/**
 * Message group that contains a subset of keys of another group.
 *
 * @author Niklas Laxström
 * @license GPL-2.0-or-later
 */
class SubsetMessageGroup extends MessageGroupOld {
	private string $parentId;
	private array $subsetKeys;
	private ?MessageGroup $parentGroup;
	private ?array $keyCache = null;
	private ?array $tagCache = null;
	private ?array $definitionsCache = null;
	/** Hack to allow AggregateMessageGroup as a subset and aggregate parent */
	private bool $recursion = false;

	public function __construct(
		string $id,
		string $label,
		string $parentId,
		array $subsetKeys
	) {
		$this->id = $id;
		$this->label = $label;
		$this->subsetKeys = $subsetKeys;
	}

	/** @inheritDoc */
	public function isMeta() {
		return true;
	}

	/** @inheritDoc */
	public function exists() {
		return true;
	}

	/** @inheritDoc */
	public function load( $code ) {
		return [];
	}

	/** @inheritDoc */
	public function getKeys() {
		if ( $this->recursion ) {
			return [];
		}

		$this->recursion = true;
		if ( $this->keyCache === null ) {

			$parentKeys = $this->getParentGroup()->getKeys();
			$commonKeys = array_intersect( $this->subsetKeys, $parentKeys );
			if ( count( $commonKeys ) < count( $this->subsetKeys ) ) {
				$unknownKeyList = implode( ', ', array_diff( $this->subsetKeys, $commonKeys ) );
				error_log( 'Invalid top messages: ' . $unknownKeyList );
			}

			$this->keyCache = array_values( $commonKeys );
		}
		$this->recursion = false;

		return $this->keyCache;
	}

	/** @inheritDoc */
	public function getDefinitions() {
		if ( $this->recursion ) {
			return [];
		}

		$this->recursion = true;
		if ( $this->definitionsCache === null ) {
			$parent = $this->getParentGroup();
			$sourceLanguage = $parent->getSourceLanguage();

			$defs = [];
			foreach ( $this->getKeys() as $key ) {
				$defs[$key] = $parent->getMessage( $key, $sourceLanguage );
			}
			$this->definitionsCache = $defs;
		}

		$this->recursion = false;

		return $this->definitionsCache;
	}

	/** @inheritDoc */
	public function getTags( $type = null ) {
		if ( $this->recursion ) {
			return [];
		}

		$this->recursion = true;
		$this->tagCache ??= $this->getParentGroup()->getTags( null );
		$this->recursion = false;

		return $type ? $this->tagCache[$type] ?? [] : $this->tagCache;
	}

	/** @inheritDoc */
	public function getMessage( $key, $code ) {
		if ( $this->recursion ) {
			return null;
		}
		$this->recursion = true;

		$value = $this->getParentGroup()->getMessage( $key, $code );

		$this->recursion = false;
		return $value;
	}

	protected function getParentGroup(): MessageGroup {
		// Protected for testing, until this code is refactored to not call static methods
		$this->parentGroup ??= MessageGroups::getGroup( $this->parentId );
		return $this->parentGroup;
	}
}
