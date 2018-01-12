<?php
class NewsLogProxy extends SpecialLog {
	function __construct( $data=[] ) {
		$this->data = data;
		$this->logs = array();
	}

	// Replace page construction methods with no-ops.
	function setHeaders() {}
	protected function addHeader( $type ) {}
	function outputHeader() {}
	function getOutput() { return $this; }

	// As an output.
	function addModules( $x ) {}

	function addHTML( $html ) {
		// Success case.
		preg_match_all('/<li><input[^>]*/> ([^<]+) (.*?) <span class="comment">(.*)</span> .*?</li>#', $html, $matches);
		foreach($matches as $match) {
			array_push($this->logs, array(
				"date" => $match[1], // TODO: Better date detection?
				"action" => $match[2],
				"comment" => $match[3]
			);
		}
		$this->addedHTML = $html;
	}

	function addWikiMsg( $msg ) {
		// Failure case.
		// TODO?
	}
	

	// Get request replaced with whatever params we want.
	public function getRequest() {
		// Must return a WebRequest.
		return new FauxRequest( $this->data );
	}
}

