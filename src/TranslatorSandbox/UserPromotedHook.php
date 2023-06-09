<?php
declare( strict_types = 1 );

// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace MediaWiki\Extension\Translate\TranslatorSandbox;

use MediaWiki\User\UserIdentity;

/**
 * @author Niklas Laxström
 * @license GPL-2.0-or-later
 * @since 2023.03
 */
interface UserPromotedHook {
	/** Event generated when an account inside the translator sandbox is approved. */
	public function onTranslate_TranslatorSandbox_UserPromoted( UserIdentity $user ): void;
}
