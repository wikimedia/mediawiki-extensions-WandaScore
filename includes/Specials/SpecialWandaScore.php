<?php

namespace MediaWiki\Extension\WandaScore\Specials;

use MediaWiki\Html\Html;
use MediaWiki\Title\Title;
use SpecialPage;

class SpecialWandaScore extends SpecialPage {

	public function __construct() {
		parent::__construct( 'WandaScore' );
	}

	/**
	 * Execute the special page
	 *
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->outputHeader();

		$out = $this->getOutput();
		$request = $this->getRequest();

		// Get page parameter
		$pageTitle = $request->getText( 'page', $subPage ?? '' );

		if ( !$pageTitle ) {
			// Show form to enter page title
			$this->showPageSelectionForm();
			return;
		}

		// Validate the page exists
		$title = Title::newFromText( $pageTitle );
		if ( !$title || !$title->exists() ) {
			$out->addHTML(
				Html::errorBox(
					$this->msg( 'wandascore-error-page-not-found', $pageTitle )->parse()
				)
			);
			$this->showPageSelectionForm();
			return;
		}

		// Add the review module
		$out->addModules( 'ext.wandascore.review' );

		// Pass page information to JavaScript
		$out->addJsConfigVars(
			[
			'wgWandaScorePageTitle' => $title->getPrefixedText(),
			'wgWandaScorePageId' => $title->getArticleID(),
			'wgWandaScorePageUrl' => $title->getFullURL()
			]
		);

		// Add container for Vue component
		$out->addHTML(
			Html::rawElement(
				'div',
				[ 'id' => 'wandascore-review-container' ],
				Html::element(
					'div',
					[ 'class' => 'wandascore-loading' ],
					$this->msg( 'wandascore-review-loading' )->text()
				)
			)
		);
	}

	/**
	 * Show form to select a page
	 */
	private function showPageSelectionForm() {
		$out = $this->getOutput();

		$formDescriptor = [
		'page' => [
		'type' => 'title',
		'name' => 'page',
		'label-message' => 'wandascore-form-page-label',
		'required' => true,
		'exists' => true
		]
		];

		$htmlForm = \HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'wandascore-form-legend' )
			->setSubmitTextMsg( 'wandascore-form-submit' )
			->prepareForm()
			->displayForm( false );
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName() {
		return 'wiki';
	}
}
