<?php

class VisualDataJsonDataContentHandler extends \JsonContentHandler {
	/**
	 * @inheritDoc
	 */
	public function __construct( $modelId = CONTENT_MODEL_VISUALDATA_JSONDATA ) {
		parent::__construct( $modelId );
	}

	/**
	 * @return string
	 */
	protected function getContentClass() {
		return VisualDataJsonDataContent::class;
	}

}
