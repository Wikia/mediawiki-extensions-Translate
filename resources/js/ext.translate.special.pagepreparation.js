( function () {
	'use strict';

	/**
	 * Save the page with a given page name and given content to the wiki.
	 *
	 * @param {string} pageName Page title
	 * @param {string} pageContent Content of the page to be saved
	 * @return {jQuery.Promise}
	 */
	function savePage( pageName, pageContent ) {
		var api = new mw.Api();

		return api.postWithToken( 'csrf', {
			action: 'edit',
			title: pageName,
			text: pageContent,
			summary: $( '#pp-summary' ).val()
		} ).promise();
	}

	/**
	 * Get the diff between the current revision and the prepared page content.
	 *
	 * @param {string} pageName Page title
	 * @param {string} pageContent Content of the page to be saved
	 * @return {jQuery.Promise}
	 * @return {Function} return.done
	 * @return {string} return.done.data
	 */
	function getDiff( pageName, pageContent ) {
		var api = new mw.Api();

		return api.post( {
			action: 'query',
			prop: 'revisions',
			rvprop: 'content',
			rvlimit: '1',
			titles: pageName,
			rvdifftotext: pageContent
		} ).then( function ( data ) {
			var obj;
			for ( var page in data.query.pages ) {
				obj = data.query.pages[ page ];
			}

			var diff;
			if ( obj !== undefined ) {
				diff = obj.revisions[ 0 ].diff[ '*' ];
			}

			return diff;
		} );
	}

	/**
	 * Get a regex snippet matching any aliases (including the canonical
	 * name) of the given namespace, or of “other” namespaces if the
	 * namespace is not given.
	 *
	 * @param {number|null} filter The namespace ID to filter for, or
	 *  `null` to filter for “other” namespaces
	 * @return {string} The aliases of the specified namespace(s),
	 *  regex-escaped and joined with `|`
	 */
	function getNamespaceRegex( filter ) {
		var aliases = [];
		var namespacesObject = mw.config.get( 'wgNamespaceIds' );
		for ( var key in namespacesObject ) {
			if ( filter !== null ) {
				if ( namespacesObject[ key ] === filter ) {
					aliases.push( mw.util.escapeRegExp( key ) );
				}
			} else {
				switch ( namespacesObject[ key ] ) {
					case -1:
					case 0:
					case 6:
					case 7:
					case 14:
					case 15:
						// not needed or handled somewhere else
						break;
					default:
						aliases.push( mw.util.escapeRegExp( key ) );
				}
			}
		}

		return aliases.join( '|' );
	}

	/**
	 * Remove all the <translate> tags and {{translation}} templates before
	 * preparing the page. The tool will add them back wherever needed.
	 *
	 * @param {string} pageContent
	 * @return {string}
	 */
	function cleanupTags( pageContent ) {
		pageContent = pageContent.replace( /<\/?translate>\n?/gi, '' );
		return pageContent;
	}

	/**
	 * Add the <languages/> bar at the top of the page, if not present.
	 * Remove the old {{languages}} template, if present.
	 *
	 * @param {string} pageContent
	 * @return {string}
	 */
	function addLanguageBar( pageContent ) {
		if ( !/<languages ?\/>/gi.test( pageContent ) ) {
			pageContent = '<languages/>\n' + pageContent;
		}
		pageContent = pageContent.replace( /\{\{languages.*?\}\}/gi, '' );
		return pageContent;
	}

	/**
	 * Add <translate> tags around Categories to make them a part of the page template
	 * and tag them with the {{translation}} template.
	 *
	 * @param {string} pageContent
	 * @return {string}
	 */
	function doCategories( pageContent ) {
		var aliasList = getNamespaceRegex( 14 );
		// Regex: https://regex101.com/r/sJ3gZ4/2
		var categoryRegex = new RegExp( '\\[\\[((' + aliasList + ')' +
			':[^\\|]+)(\\|[^\\|]*?)?\\]\\]', 'gi' );
		pageContent = pageContent.replace( categoryRegex, '\n</translate>\n' +
			'[[$1{{#translation:}}$3]]\n<translate>\n' );

		return pageContent;
	}

	/**
	 * Add the <translate> and </translate> tags at the start and end of the page.
	 * The opening tag is added immediately after the <languages/> tag.
	 *
	 * @param {string} pageContent
	 * @return {string}
	 */
	function addTranslateTags( pageContent ) {
		pageContent = pageContent.replace( /(<languages ?\/>\n)/gi, '$1<translate>\n' );
		pageContent = pageContent + '\n</translate>';
		return pageContent;
	}

	/**
	 * Add newlines before and after section headers. Extra newlines resulting after
	 * this operation are cleaned up in postPreparationCleanup() function.
	 *
	 * @param {string} pageContent
	 * @return {string}
	 */
	function addNewLines( pageContent ) {
		pageContent = pageContent.replace( /^(==.*==)\n*/gm, '\n$1\n\n' );
		return pageContent;
	}

	/**
	 * Add an anchor to a section header with the given headerText
	 *
	 * @param {string} headerText
	 * @param {string} pageContent
	 * @return {string}
	 */
	function addAnchor( headerText, pageContent ) {
		var anchorID = headerText.replace( ' ', '-' ).toLowerCase();

		headerText = mw.util.escapeRegExp( headerText );
		// Search for the header having text as headerText
		// Regex: https://regex101.com/r/fD6iL1
		var headerSearchRegex = new RegExp( '(==+[ ]*' + headerText + '[ ]*==+)', 'gi' );
		// This is to ensure the tags and the anchor are added only once

		if ( pageContent.indexOf( '<span id="' + mw.html.escape( anchorID ) + '"' ) === -1 ) {
			pageContent = pageContent.replace( headerSearchRegex, '</translate>\n' +
				'<span id="' + mw.html.escape( anchorID ) + '"></span>\n<translate>\n$1' );
		}

		// This is to add back the tags which were removed in cleanupTags()
		if ( pageContent.indexOf( '</translate>\n<span id="' + anchorID + '"' ) === -1 ) {
			var spanSearchRegex = new RegExp( '(<span id="' + mw.util.escapeRegExp( anchorID ) + '"></span>)', 'gi' );
			pageContent = pageContent.replace( spanSearchRegex, '\n</translate>\n$1\n</translate>\n' );
		}

		// Replace the link text with the anchorID defined above
		// Regex: https://regex101.com/r/kB5bK3
		var replaceAnchorRegex = new RegExp( '(\\[\\[#)' + headerText + '(.*\\]\\])', 'gi' );
		pageContent = pageContent.replace( replaceAnchorRegex, '$1' +
			anchorID.replace( '$', '$$$' ) + '$2' );

		return pageContent;
	}

	/**
	 * Convert all the links into two-party form and add the 'Special:MyLanguage/' prefix
	 * to links in valid namespaces for the wiki. For example, [[Example]] would be converted
	 * to [[Special:MyLanguage/Example|Example]].
	 *
	 * @param {string} pageContent
	 * @return {string}
	 */
	function fixInternalLinks( pageContent ) {
		var searchText = pageContent;

		var categoryNsString = getNamespaceRegex( 14 );
		var normalizeRegex = new RegExp( '\\[\\[(?!' + categoryNsString + ')([^|]*?)\\]\\]', 'gi' );
		// First convert all links into two-party form. If a link is not having a pipe,
		// add a pipe and duplicate the link text
		// Regex: https://regex101.com/r/pO9nN2
		pageContent = pageContent.replace( normalizeRegex, '[[$1|$1]]' );

		var nsString = getNamespaceRegex( null );
		// Finds all the links to sections on the same page.
		// Regex: https://regex101.com/r/cX6jT3
		var sectionLinksRegex = new RegExp( /\[\[#(.*?)(\|(.*?))?\]\]/gi );
		var match = sectionLinksRegex.exec( searchText );
		while ( match !== null ) {
			pageContent = addAnchor( match[ 1 ], pageContent );
			match = sectionLinksRegex.exec( searchText );
		}

		var linkPrefixRegex = new RegExp( '\\[\\[((?:(?:special(?!:MyLanguage\\b)|' + nsString +
			'):)?[^:]*?)\\]\\]', 'gi' );
		// Add the 'Special:MyLanguage/' prefix for all internal links of valid namespaces and
		// mainspace.
		// Regex: https://regex101.com/r/zZ9jH9
		pageContent = pageContent.replace( linkPrefixRegex, '[[Special:MyLanguage/$1]]' );
		return pageContent;
	}

	/**
	 * Add translate tags around only translatable content for files and keep everything else
	 * as a part of the page template.
	 *
	 * @param {string} pageContent
	 * @return {string}
	 */
	function doFiles( pageContent ) {
		var aliasList = getNamespaceRegex( 6 );

		// Add translate tags for files with captions
		var captionFilesRegex = new RegExp( '\\[\\[(' + aliasList + ')(.*\\|)(.*?)\\]\\]', 'gi' );
		pageContent = pageContent.replace( captionFilesRegex,
			'</translate>\n[[$1$2<translate>$3</translate>]]\n<translate>' );

		// Add translate tags for files without captions
		var fileRegex = new RegExp( '/\\[\\[((' + aliasList + ')[^\\|]*?)\\]\\]', 'gi' );
		pageContent = pageContent.replace( fileRegex, '\n</translate>[[$1]]\n<translate>' );

		return pageContent;
	}

	/**
	 * Keep templates outside <translate>....</translate> tags
	 * Does not deal with nested templates, needs manual changes.
	 *
	 * @param {string} pageContent
	 * @return {string} pageContent
	 */
	function doTemplates( pageContent ) {
		// Regex: https://regex101.com/r/wA3iX0
		var templateRegex = new RegExp( /^({{[\s\S]*?}})/gm );

		pageContent = pageContent.replace( templateRegex, '</translate>\n$1\n<translate>' );
		return pageContent;
	}

	/**
	 * Cleanup done after the page is prepared for translation by the tool.
	 *
	 * @param {string} pageContent
	 * @return {string}
	 */
	function postPreparationCleanup( pageContent ) {
		// Removes any extra newlines introduced by the tool
		pageContent = pageContent.replace( /\n\n+/gi, '\n\n' );
		// Removes redundant <translate> tags
		pageContent = pageContent.replace( /\n<translate>(\n*?)<\/translate>/gi, '' );
		// Removes the Special:MyLanguage/ prefix for section links
		pageContent = pageContent.replace( /Special:MyLanguage\/#/gi, '#' );
		return pageContent;
	}

	/**
	 * Get the current revision for the given page.
	 *
	 * @param {string} pageName
	 * @return {jQuery.Promise}
	 * @return {Function} return.done
	 * @return {string} return.done.value The current revision
	 */
	function getPageContent( pageName ) {
		var api = new mw.Api();

		return api.get( {
			action: 'query',
			prop: 'revisions',
			rvprop: 'content',
			rvlimit: '1',
			titles: pageName
		} ).then( function ( data ) {
			var obj;

			for ( var page in data.query.pages ) {
				obj = data.query.pages[ page ];
			}

			return obj.revisions[ 0 ][ '*' ];
		} );
	}

	$( function () {
		var $input = $( '#page' );

		$( '#action-cancel' ).on( 'click', function () {
			document.location.reload( true );
		} );

		var pageContent;
		$( '#action-save' ).on( 'click', function () {
			var pageUrl = '';

			var pageName = $input.val().trim();
			savePage( pageName, pageContent ).done( function () {
				pageUrl = mw.Title.newFromText( pageName ).getUrl( { action: 'edit' } );
				$( '.messageDiv' )
					.empty()
					.append( mw.message( 'pp-save-message', pageUrl ).parseDom() )
					.removeClass( 'hide' );
				$( '.divDiff' ).addClass( 'hide' );
				$( '#action-prepare' ).removeClass( 'hide' );
				$input.val( '' );
				$( '#action-save' ).addClass( 'hide' );
				$( '#action-cancel' ).addClass( 'hide' );
			} );
		} );

		$( '#action-prepare' ).on( 'click', function () {
			var $messageDiv = $( '.messageDiv' );

			var pageName = $input.val().trim();
			$messageDiv.addClass( 'hide' );
			if ( pageName === '' ) {
				// eslint-disable-next-line no-alert
				alert( mw.msg( 'pp-pagename-missing' ) );
				return;
			}

			$.when( getPageContent( pageName ) ).done( function ( content ) {
				pageContent = content;
				pageContent = pageContent.trim();
				pageContent = cleanupTags( pageContent );
				pageContent = addLanguageBar( pageContent );
				pageContent = addTranslateTags( pageContent );
				pageContent = addNewLines( pageContent );
				pageContent = fixInternalLinks( pageContent );
				pageContent = doTemplates( pageContent );
				pageContent = doFiles( pageContent );
				pageContent = doCategories( pageContent );
				pageContent = postPreparationCleanup( pageContent );
				pageContent = pageContent.trim();
				getDiff( pageName, pageContent ).done( function ( diff ) {
					if ( diff === undefined ) {
						$messageDiv.text( mw.msg( 'pp-diff-error' ) ).removeClass( 'hide' );
						return;
					}

					$( '.diff tbody' ).append( diff );
					$( '.divDiff' ).removeClass( 'hide' );
					if ( diff !== '' ) {
						$messageDiv.text( mw.msg( 'pp-prepare-message' ) ).removeClass( 'hide' );
						$( '#action-prepare' ).addClass( 'hide' );
						$( '#action-save' ).removeClass( 'hide' );
						$( '#action-cancel' ).removeClass( 'hide' );
					} else {
						$messageDiv.text( mw.msg( 'pp-already-prepared-message' ) ).removeClass( 'hide' );
					}
				} );
			} );
		} );
	} );

}() );
