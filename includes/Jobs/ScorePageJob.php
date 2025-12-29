<?php
/**
 * Job to score a page
 *
 * @author  Sanjay Thiyagarajan <sanjayipscoc@gmail.com>
 * @file
 * @ingroup WandaScore
 */

namespace MediaWiki\Extension\WandaScore\Jobs;

use Job;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;

class ScorePageJob extends Job {

	/**
	 * @param Title $title
	 * @param array $params
	 */
	public function __construct( $title, $params ) {
		parent::__construct( 'WandaScorePage', $title, $params );
	}

	/**
	 * Execute the job
	 *
	 * @return bool
	 */
	public function run() {
		$pageTitle = $this->params['pageTitle'];

		// Call the API internally to generate the score
		$api = new \ApiMain(
			new FauxRequest(
				[
				'action' => 'wandascore',
				'page' => $pageTitle,
				'refresh' => true
				],
				true
			),
			true
		);

		try {
			$api->execute();
			wfDebugLog( 'WandaScore', "Successfully scored page: {$pageTitle}" );
			return true;
		} catch ( \Exception $e ) {
			wfDebugLog( 'WandaScore', "Error scoring page {$pageTitle}: " . $e->getMessage() );
			return false;
		}
	}
}
