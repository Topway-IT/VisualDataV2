<?php

class VisualDataJsonDataContent extends \JsonContent {
	/**
	 * @inheritDoc
	 */
	public function __construct( $text, $modelId = CONTENT_MODEL_VISUALDATA_JSONDATA ) {
		parent::__construct( $text, $modelId );
	}
}
