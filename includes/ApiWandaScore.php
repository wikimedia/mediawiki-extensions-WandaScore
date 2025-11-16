<?php

namespace MediaWiki\Extension\WandaScore;

use ApiBase;
use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;
use WikiPage;

class ApiWandaScore extends ApiBase {

	/**
	 * Execute the API request
	 */
	public function execute() {
		$params = $this->extractRequestParams();
		$pageTitle = $params['page'];
		$forceRefresh = $params['refresh'];

		// Get the title object
		$title = \Title::newFromText( $pageTitle );
		if ( !$title || !$title->exists() ) {
			$this->dieWithError( [ 'apierror-missingtitle', $pageTitle ] );
		}

		// Check if we have a cached score
		if ( !$forceRefresh ) {
			$cachedScore = $this->getCachedScore( $title->getArticleID() );
			if ( $cachedScore ) {
				$this->getResult()->addValue( null, 'wandascore', $cachedScore );
				return;
			}
		}

		// Generate a new score
		$score = $this->generateScore( $title );

		if ( $score === false ) {
			$this->dieWithError( 'wandascore-error-generation-failed' );
		}

		// Cache the score
		$this->cacheScore( $title->getArticleID(), $score );

		// Return the score
		$this->getResult()->addValue( null, 'wandascore', $score );
	}

	/**
	 * Generate a score for a page by calling Wanda API multiple times
	 *
	 * @param \Title $title
	 * @return array|false
	 */
	private function generateScore( $title ) {
		$wikiPage = new WikiPage( $title );
		$content = $wikiPage->getContent();

		if ( !$content ) {
			return false;
		}

		$text = $content->getText();
		if ( strlen( $text ) < 50 ) {
			// Too short to meaningfully review
			return [
			'overall_score' => 50,
			'factors' => [
			'bias' => [ 'score' => 50, 'details' => 'Content too short for analysis' ],
			'llm_generated' => [ 'score' => 50, 'details' => 'Content too short for analysis' ],
			'language' => [ 'score' => 50, 'details' => 'Content too short for analysis' ],
			'grammar' => [ 'score' => 50, 'details' => 'Content too short for analysis' ],
			'conciseness' => [ 'score' => 50, 'details' => 'Content too short for analysis' ]
			],
			'timestamp' => wfTimestamp( TS_MW )
			];
		}

		// Prepare content summary (limit to 3000 chars for API calls)
		$contentSummary = mb_substr( $text, 0, 3000 );
		$pageUrl = $title->getFullURL();

		// Call Wanda API for each factor
		$factors = [];

		// 1. Bias Detection
		$biasResult = $this->callWandaChat(
			$contentSummary,
			"Analyze the following wiki page content for bias. Rate it on a scale of 0-100 " .
			"where 100 means completely neutral and unbiased, and 0 means extremely biased. " .
			"Provide a brief explanation. Format your response as: SCORE: [number] DETAILS: [explanation]\n"
		);
		$factors['bias'] = $this->parseScoreResponse( $biasResult, 80 );

		// 2. LLM-Generated Content Detection
		$llmResult = $this->callWandaChat(
			$contentSummary,
			"Analyze if the following wiki page content appears to be AI/LLM-generated. " .
			"Rate it on a scale of 0-100 where 100 means definitely human-written and original, " .
			"and 0 means definitely AI-generated. Look for signs like repetitive patterns, " .
			"generic phrasing, or lack of personal voice. Format your response as: SCORE: [number] " .
			"DETAILS: [explanation]\n"
		);
		$factors['llm_generated'] = $this->parseScoreResponse( $llmResult, 70 );

		// 3. Language Quality Score
		$languageResult = $this->callWandaChat(
			$contentSummary,
			"Evaluate the language quality of the following wiki page content. " .
			"Rate it on a scale of 0-100 where 100 means excellent, clear, professional language, " .
			"and 0 means poor language quality. Consider clarity, vocabulary, and readability. " .
			"Format your response as: SCORE: [number] DETAILS: [explanation]\n"
		);
		$factors['language'] = $this->parseScoreResponse( $languageResult, 75 );

		// 4. Grammar Check
		$grammarResult = $this->callWandaChat(
			$contentSummary,
			"Check the grammar and spelling of the following wiki page content. " .
			"Rate it on a scale of 0-100 where 100 means perfect grammar with no errors, " .
			"and 0 means numerous grammar and spelling errors. " .
			"Format your response as: SCORE: [number] DETAILS: [explanation]\n"
		);
		$factors['grammar'] = $this->parseScoreResponse( $grammarResult, 80 );

		// 5. Conciseness
		$concisenessResult = $this->callWandaChat(
			$contentSummary,
			"Evaluate the conciseness of the following wiki page content. " .
			"Rate it on a scale of 0-100 where 100 means perfectly concise with no unnecessary verbosity, " .
			"and 0 means extremely verbose and repetitive. " .
			"Format your response as: SCORE: [number] DETAILS: [explanation]\n"
		);
		$factors['conciseness'] = $this->parseScoreResponse( $concisenessResult, 75 );

		// Calculate overall score (weighted average)
		$weights = [
		'bias' => 1.5,
		'llm_generated' => 1.0,
		'language' => 1.2,
		'grammar' => 1.2,
		'conciseness' => 1.0
		];

		$totalWeight = array_sum( $weights );
		$weightedSum = 0;

		foreach ( $factors as $key => $factor ) {
			$weightedSum += $factor['score'] * $weights[$key];
		}

		$overallScore = round( $weightedSum / $totalWeight );

		return [
		'overall_score' => $overallScore,
		'factors' => $factors,
		'timestamp' => wfTimestamp( TS_MW ),
		'page_id' => $title->getArticleID(),
		'page_title' => $title->getPrefixedText()
		];
	}

	/**
	 * Call the Wanda API with a question
	 *
	 * @param string $question
	 * @param string $instructions
	 * @return string|false
	 */
	private function callWandaChat( $question, $instructions ) {
		$api = new \ApiMain(
			new \DerivativeRequest(
				$this->getRequest(),
				[
				'action' => 'wandachat',
				'message' => $question,
				'usepublicknowledge' => true,
					'skipesquery' => true,
					'customprompt' => $instructions,
					'temperature' => '0',
					'maxtokens' => '10000'
				],
				true
			)
		);

		try {
			$api->execute();
			$result = $api->getResult()->getResultData();

			if ( isset( $result['response'] ) ) {
				return $result['response'];
			}
		} catch ( \Exception $e ) {
			wfDebugLog( 'WandaScore', 'Error calling Wanda API: ' . $e->getMessage() );
		}

		return false;
	}

	/**
	 * Parse score response from Wanda
	 *
	 * @param string|false $response
	 * @param int $defaultScore
	 * @return array
	 */
	private function parseScoreResponse( $response, $defaultScore = 50 ) {
		if ( !$response ) {
			return [
			'score' => $defaultScore,
			'details' => 'Unable to analyze this factor'
			];
		}

		// Try to extract SCORE: and DETAILS: from response
		$score = $defaultScore;
		$details = $response;

		if ( preg_match( '/SCORE:\s*(\d+)/i', $response, $scoreMatches ) ) {
			$score = max( 0, min( 100, intval( $scoreMatches[1] ) ) );
		}

		if ( preg_match( '/DETAILS:\s*(.+?)(?:SCORE:|$)/is', $response, $detailMatches ) ) {
			$details = trim( $detailMatches[1] );
		} elseif ( preg_match( '/SCORE:\s*\d+\s*(.+?)$/is', $response, $detailMatches ) ) {
			$details = trim( $detailMatches[1] );
		}

		// Remove SCORE: and DETAILS: prefixes from details if present
		$details = preg_replace( '/^(SCORE:\s*\d+\s*|DETAILS:\s*)/i', '', $details );

		// Clean up and format the details text for better readability
		$details = $this->formatDetailsText( trim( $details ) );

		return [
		'score' => $score,
		'details' => $details ?: 'No additional details available'
		];
	}

	/**
	 * Format details text by converting markdown-style formatting to HTML
	 *
	 * @param string $text
	 * @return string
	 */
	private function formatDetailsText( $text ) {
		if ( empty( $text ) ) {
			return $text;
		}

		// Convert **bold** to <strong>
		$text = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text );
		$hasBulletPoints = preg_match( '/^\s*\*\s+/m', $text );
		$hasNumberedPoints = preg_match( '/^\s*\d+\.\s+/m', $text );

		if ( $hasBulletPoints || $hasNumberedPoints ) {
			// Split into lines and process
			$lines = explode( "\n", $text );
			$inUnorderedList = false;
			$inOrderedList = false;
			$result = [];

			foreach ( $lines as $line ) {
				$trimmed = trim( $line );
				if ( preg_match( '/^\d+\.\s+(.+)$/', $trimmed, $matches ) ) {
					if ( $inUnorderedList ) {
						$result[] = '</ul>';
						$inUnorderedList = false;
					}
					if ( !$inOrderedList ) {
						$result[] = '<ol>';
						$inOrderedList = true;
					}
					$result[] = '<li>' . trim( $matches[1] ) . '</li>';
				}
				// Check if this is a bullet point
				elseif ( preg_match( '/^\*\s+(.+)$/', $trimmed, $matches ) ) {
					if ( $inOrderedList ) {
						$result[] = '</ol>';
						$inOrderedList = false;
					}
					if ( !$inUnorderedList ) {
						$result[] = '<ul>';
						$inUnorderedList = true;
					}
					$result[] = '<li>' . trim( $matches[1] ) . '</li>';
				} else {
					if ( $inUnorderedList ) {
						$result[] = '</ul>';
						$inUnorderedList = false;
					}
					if ( $inOrderedList ) {
						$result[] = '</ol>';
						$inOrderedList = false;
					}
					if ( !empty( $trimmed ) ) {
						$result[] = '<p>' . $trimmed . '</p>';
					}
				}
			}
			if ( $inUnorderedList ) {
				$result[] = '</ul>';
			}
			if ( $inOrderedList ) {
				$result[] = '</ol>';
			}

			$text = implode( "\n", $result );
		} else {
			// No bullet points or numbered lists, just wrap in paragraphs for better spacing
			$paragraphs = preg_split( '/\n\s*\n/', $text );
			$formatted = [];
			foreach ( $paragraphs as $para ) {
				$para = trim( $para );
				if ( !empty( $para ) ) {
					// Replace single newlines with spaces within paragraphs
					$para = preg_replace( '/\s*\n\s*/', ' ', $para );
					$formatted[] = '<p>' . $para . '</p>';
				}
			}
			$text = implode( "\n", $formatted );
		}

		return $text;
	}

	/**
	 * Get cached score from database
	 *
	 * @param int $pageId
	 * @return array|false
	 */
	private function getCachedScore( $pageId ) {
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getConnection( DB_REPLICA );

		$row = $dbr->selectRow(
			'wandascore',
			[ 'ws_score_data', 'ws_timestamp' ],
			[ 'ws_page_id' => $pageId ],
			__METHOD__
		);

		if ( $row ) {
			$data = json_decode( $row->ws_score_data, true );
			if ( $data ) {
				return $data;
			}
		}

		return false;
	}

	/**
	 * Cache score in database
	 *
	 * @param int $pageId
	 * @param array $score
	 */
	private function cacheScore( $pageId, $score ) {
		$dbw = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getConnection( DB_PRIMARY );

		$dbw->upsert(
			'wandascore',
			[
			'ws_page_id' => $pageId,
			'ws_overall_score' => $score['overall_score'],
			'ws_score_data' => json_encode( $score ),
			'ws_timestamp' => $dbw->timestamp()
			],
			[ 'ws_page_id' ],
			[
			'ws_overall_score' => $score['overall_score'],
			'ws_score_data' => json_encode( $score ),
			'ws_timestamp' => $dbw->timestamp()
			],
			__METHOD__
		);
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams() {
		return [
		'page' => [
		ParamValidator::PARAM_TYPE => 'string',
		ParamValidator::PARAM_REQUIRED => true
		],
		'refresh' => [
		ParamValidator::PARAM_TYPE => 'boolean',
		ParamValidator::PARAM_DEFAULT => false
		]
		];
	}

	/**
	 * @inheritDoc
	 */
	public function needsToken() {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function isWriteMode() {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamplesMessages() {
		return [
		'action=wandascore&page=Main_Page'
		=> 'apihelp-wandascore-example-1',
		'action=wandascore&page=Main_Page&refresh=true'
		=> 'apihelp-wandascore-example-2'
		];
	}
}
