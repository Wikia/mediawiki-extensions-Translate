<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\Translate\Jobs;

use Job;
use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Generic Job class extended by other jobs. Provides logging functionality.
 * @author Abijeet Patro
 * @license GPL-2.0-or-later
 * @since 2019.08
 */
abstract class GenericTranslateJob extends Job {
	/** @var LoggerInterface */
	private $logger;

	/** @var string Channel name to be used during logging */
	private const CHANNEL_NAME = 'Translate.Jobs';

	/**
	 * Returns a logger instance with the channel name. Can have only a single
	 * channel per job, so once instantiated, the same instance is returned.
	 */
	private function getLogger(): LoggerInterface {
		if ( $this->logger ) {
			return $this->logger;
		}

		$this->logger = LoggerFactory::getInstance( self::CHANNEL_NAME );
		return $this->logger;
	}

	/** @phan-return array{0:string,1:array} */
	private function formatLogEntry( string $msg, array $context = [] ): array {
		$prefix = $this->getType();
		if ( isset( $this->title ) ) {
			$prefix .= ' [{job_title}]';
			$context['job_title'] = $this->title->getPrefixedText();
		}

		return [ "$prefix: $msg", $context ];
	}

	protected function logDebug( string $msg, array $context = [] ): void {
		[ $msg, $context ] = $this->formatLogEntry( $msg, $context );
		$this->getLogger()->debug( $msg, $context );
	}

	protected function logInfo( string $msg, array $context = [] ): void {
		[ $msg, $context ] = $this->formatLogEntry( $msg, $context );
		$this->getLogger()->info( $msg, $context );
	}

	protected function logNotice( string $msg, array $context = [] ): void {
		[ $msg, $context ] = $this->formatLogEntry( $msg, $context );
		$this->getLogger()->notice( $msg, $context );
	}

	protected function logWarning( string $msg, array $context = [] ): void {
		[ $msg, $context ] = $this->formatLogEntry( $msg, $context );
		$this->getLogger()->warning( $msg, $context );
	}

	protected function logError( string $msg, array $context = [] ): void {
		[ $msg, $context ] = $this->formatLogEntry( $msg, $context );
		$this->getLogger()->error( $msg, $context );
	}
}
