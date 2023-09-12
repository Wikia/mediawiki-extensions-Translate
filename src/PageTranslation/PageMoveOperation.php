<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\Translate\PageTranslation;

use Title;

/**
 * Represents a single page being moved including the talk page.
 * @author Abijeet Patro
 * @license GPL-2.0-or-later
 * @since 2021.09
 */
class PageMoveOperation {
	private Title $old;
	private ?Title $new;
	private ?Title $oldTalkpage = null;
	private ?Title $newTalkpage = null;
	private ?InvalidPageTitleRename $invalidPageTitleRename;

	public function __construct( Title $old, ?Title $new, ?InvalidPageTitleRename $e = null ) {
		$this->old = $old;
		$this->new = $new;
		$this->invalidPageTitleRename = $e;
	}

	public function getOldTitle(): Title {
		return $this->old;
	}

	public function getNewTitle(): ?Title {
		return $this->new;
	}

	public function getOldTalkpage(): ?Title {
		return $this->oldTalkpage;
	}

	public function getNewTalkpage(): ?Title {
		return $this->newTalkpage;
	}

	public function hasTalkpage(): bool {
		return isset( $this->oldTalkpage );
	}

	public function getRenameErrorCode(): int {
		return $this->invalidPageTitleRename ?
			$this->invalidPageTitleRename->getCode() : PageTitleRenamer::NO_ERROR;
	}

	public function setTalkpage( Title $oldTalkpage, ?Title $newTalkpage ): void {
		$this->oldTalkpage = $oldTalkpage;
		$this->newTalkpage = $newTalkpage;
	}
}
