/*jshint esversion: 6,  node:true */

/**
 * Base page denoting a Title (can be article or special pages).
 */

const Page = require('./page');

class TitlePage extends Page {
	constructor( title ) {
		super();
		if ( title ) {
			this.url = title;
		}
	}

	get url() {
		return this._url;
	}
	set url( title ) {
		super.url =  `/wiki/${title}`;
	}
}
module.exports = TitlePage;
