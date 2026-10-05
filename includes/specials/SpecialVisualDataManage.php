<?php

/**
 * This file is part of the MediaWiki extension VisualData.
 *
 * VisualData is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * VisualData is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with VisualData.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @file
 * @ingroup extensions
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright ©2026, https://wikisphere.org
 */

use MediaWiki\Extension\JsonForms\Aliases\Html as HtmlClass;
use MediaWiki\Extension\JsonForms\Aliases\Title as TitleClass;
use MediaWiki\Extension\JsonForms\SlotEditor;
use MediaWiki\Extension\JsonForms\Specials\ManagePager;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Revision\SlotRecord;

/**
 * A special page that lists protected pages
 *
 * @ingroup SpecialPage
 */
class SpecialVisualDataManage extends SpecialPage {
	/** @var user */
	public $user;

	/** @var Request */
	public $request;

	/** @var string */
	public $par;

	/** @var int */
	public $namespace;

	/** @var string */
	public $localTitle;

	/**
	 * @inheritDoc
	 */
	public function __construct() {
		$listed = false;
		parent::__construct( 'VisualDataManage', '', $listed );
	}

	/**
	 * @return string|Message
	 */
	public function getDescription() {
		$msg = $this->msg( 'visualdatabrowse' . $this->par );
		if ( version_compare( MW_VERSION, '1.40', '>' ) ) {
			return $msg;
		}
		return $msg->text();
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		// $this->requireLogin();
		$allowedItems = [ 'Queries' ];

		if ( !in_array( $par, $allowedItems ) ) {
			$this->displayRestrictionError();
			return;
		}

		$this->par = strtolower( (string)$par );
		$user = $this->getUser();

		if (
			$this->par === 'queries' &&
			!$user->isAllowed( 'visualdata-canmanagequeries' )
		) {
			$this->displayRestrictionError();
			return;
		}

		$this->setHeaders();
		$this->outputHeader();

		$out = $this->getOutput();

		$out->addModuleStyles( 'mediawiki.special' );
		$this->addHelpLink( 'Extension:VisualData' );

		$request = $this->getRequest();

		$this->request = $request;
		$this->user = $user;

		$this->addJsConfigVars( $out );

		$out->enableOOUI();

		// displays the edit icon
		$out->addModuleStyles( [
			'oojs-ui.styles.icons-editing-core',
		] );

		$this->addNavigationLinks( $par );

		$out->addWikiMsg(
			'visualdata-special-browse-' . $this->par . '-description',
		);

		$this->localTitle = SpecialPage::getTitleFor( 'VisualDataManage', $par );

		$item = null;
		switch ( $this->par ) {
			case 'queries':
			default:
				$item = 'query';
				$this->namespace = NS_VISUALDATAQUERY;
		}

		$action = $this->getRequest()->getVal( 'action' );

		$jsonForm = \JsonForms::getSourceSchema(
			'SimpleFormUI',
			'JsonSchema/Core',
		);

		if ( !$jsonForm ) {
			throw new MWException( 'Cannot load core schema' );
		}

		$formDescriptor = \JsonForms::getSourceSchema( 'Default', 'JsonForm' );

		if ( !$formDescriptor ) {
			throw new MWException( 'Cannot load core schema' );
		}

		$formDescriptor->slot = SlotRecord::MAIN;
		$formDescriptor->edit_categories = false;
		$formDescriptor->return = 'url';
		$formDescriptor->return_url = $this->localTitle->getLocalURL();

		$schemaName = '';
		$pagename = $this->getRequest()->getVal( 'pagename' );

		// from SubmitProcessors -> PageForms -> ManageSchemas
		if ( $pagename ) {
			$title_ = TitleClass::newFromText( $pagename );
			if ( $title_ ) {
				$pageid = $title_->getArticleID();
			}

		} else {
			$pageid = $this->getRequest()->getVal( 'pageid' );
		}

		if ( $pageid ) {
			$title = TitleClass::newFromID( $pageid );
			if ( !$title ) {
				return $this->printError(
					$out,
					'visualdata-special-browse-error-invalid-article',
				);
			}

			$formDescriptor->edit = $title->getFullText();
			$articleContent = \JsonForms::getArticleContent( $title );
			$schemaName = $title->getDBKey();
		}

		$startVal = [];
		switch ( $item ) {
			case 'query':
				$specialpage_title = SpecialPage::getTitleFor(
					'VisualDataManage',
					'Queries',
				);

				$formDescriptor->pagename_formula = 'VisualDataQuery:<name>';
				$innerSchema = \JsonForms::getSourceSchema(
					'VisualData/AskQuery',
					'JsonSchema',
				);

				if ( !$innerSchema ) {
					throw new MWException( 'Cannot load core schema' );
				}

				$innerSchema = \JsonForms::processSchema( $out, $innerSchema );

				// ***important, encode schema otherwise $refs can mess with
				// those of the host schema
				$config = new stdClass();
				$config->schema = json_encode( $innerSchema );

				$jsonForm->properties->editor->{'x-input-config'} = $config;
				break;
		}

		if ( $pageid ) {
			$specialPageTitle = SpecialPage::getTitleFor(
				'VisualDataManage',
				$par,
			);
			$out->addWikiMsg(
				'visualdata-special-manage-returnlink',
				$specialPageTitle->getFullText(),
			);

			$out->addHTML(
				HtmlClass::rawElement(
					'p',
					[],
					$this->msg(
						'visualdata-special-manage-schemas-schemaname',
						$title->getFullText(),
					)->parse(),
				),
			);
		}

		switch ( $action ) {
			case 'edit':
				$formData = new stdClass();
				$formData->schema = $jsonForm;
				$formData->schemaName = 'VisualData/AskQuery';
				$formDescriptor->inline_css = 'width:calc(100% - 24px); min-width: 0';

				$formDescriptor->editor_options->debug = false;

				$formData->formDescriptor = $formDescriptor;
				$formData->startval = new stdClass();

				if ( isset( $articleContent ) ) {
					$formData->startval->editor = $articleContent;
				}

				$formData = \JsonForms::prepareFormData( $out, $formData );

				$data = [];
				$res_ = \JsonForms::getJsonFormHtml( $formData );

				if ( !$res_->ok ) {
					return $this->printError( $out, $res_->error );
				}

				$html = HtmlClass::rawElement(
					'div',
					[ 'class' => 'visualdata-build-container' ],
					$res_->value,
				);

				$out->addModules( 'ext.JsonForms.ManageSchemas' );
				$out->addModules( 'ext.VisualData.AskQuery' );

				\JsonForms::addJsConfigVars( $out );

				$out->addHTML( $html );
				break;

			default:
				$layout = new OOUI\PanelLayout( [
					'id' => 'visualdata-panel-layout',
					'expanded' => false,
					'padded' => false,
					'framed' => false,
				] );

				$layout->appendContent(
					new OOUI\ButtonWidget( [
						'href' => wfAppendQuery(
							$this->localTitle->getLocalURL(),
							[ 'action' => 'edit' ],
						),
						'label' => $this->msg(
							'visualdata-manage-form-button-add-' . $item,
						)->text(),
						'infusable' => true,
						'icon' => 'add',
						'flags' => [ 'progressive', 'primary' ],
					] ),
				);

				$out->addHTML( $layout );
				$options = $this->showOptions( $request );

				if ( $options ) {
					$out->addHTML( '<br />' );
					$out->addHTML( $options );
					$out->addHTML( '<br />' );
				}

				$pager = new ManagePager( $this, $request, $this->getLinkRenderer() );

				if ( $pager->getNumRows() ) {
					$parserOptions = version_compare( MW_VERSION, '1.44', '>=' )
						? ParserOptions::newFromContext( $this->getContext() )
						: [];
					$out->addParserOutputContent(
						$pager->getFullOutput(),
						$parserOptions,
					);
				} else {
					$out->addWikiMsg( 'visualdata-special-browse-table-empty' );
				}
		}
	}

	/**
	 * @param Output $out
	 * @param string $msg
	 */
	private function printError( $out, $msg ) {
		$out->addHTML(
			new \OOUI\MessageWidget( [
				'type' => 'error',
				'label' => new \OOUI\HtmlSnippet( $this->msg( $msg )->parse() ),
			] ),
		);
	}

	/**
	 * @param Output $out
	 */
	protected function addJsConfigVars( $out ) {
		$context = $this->getContext();
		$out->addJsConfigVars( [] );
	}

	/**
	 * @see AbuseFilterSpecialPage
	 * @param string $pageType
	 */
	protected function addNavigationLinks( $pageType ) {
		$linkDefs = [
			'queries' => 'VisualDataManage/Queries',
			'data' => 'VisualDataManage/Data',
		];

		$links = [];

		foreach ( $linkDefs as $name => $page ) {
			// Give grep a chance to find the usages:
			// abusefilter-topnav-home, abusefilter-topnav-recentchanges, abusefilter-topnav-test,
			// abusefilter-topnav-log, abusefilter-topnav-tools, abusefilter-topnav-examine
			$msgName = "visualdatabrowse$name";

			$msg = $this->msg( $msgName )->parse();

			if ( $name === $pageType ) {
				$links[] = Xml::tags( 'strong', null, $msg );
			} else {
				$links[] = $this->getLinkRenderer()->makeLink(
					new TitleValue( NS_SPECIAL, $page ),
					new HtmlArmor( $msg ),
				);
			}
		}

		$linkStr = $this->msg( 'parentheses' )
			->rawParams( $this->getLanguage()->pipeList( $links ) )
			->text();
		$linkStr =
			$this->msg( 'visualdatabrowsedata-topnav' )->parse() . " $linkStr";

		$linkStr = Xml::tags(
			'div',
			[ 'class' => 'mw-visualdata-browsedata-navigation' ],
			$linkStr,
		);

		$this->getOutput()->setSubtitle( $linkStr );
	}

	/**
	 * @param Request $request
	 * @return string
	 */
	protected function showOptions( $request ) {
		$formDescriptor = [];

		switch ( $this->par ) {
			case 'schemas':
			case 'forms':
			default:
				// $schemaname = $request->getVal( "schemaname" );
				$formDescriptor['schema'] = [
					'label-message' =>
						'visualdata-special-browse-form-search-schema-label',
					'name' => 'search',
					'type' => 'title',
					'namespace' => $this->namespace,
					'relative' => true,
					'required' => false,

					// @fixme this has no effect, create a custom widget
					'limit' => 20,
					'help-message' =>
						'visualdata-special-browse-form-search-schema-help',
					'default' => $schemaname ?? null,
				];
		}

		$htmlForm = HTMLForm::factory(
			'ooui',
			$formDescriptor,
			$this->getContext(),
		);

		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'visualdata-special-browse-form-search-legend' )
			->setSubmitText(
				$this->msg(
					'visualdata-special-browse-form-search-submit',
				)->text(),
			);

		return $htmlForm->prepareForm()->getHTML( false );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'visualdata';
	}
}
