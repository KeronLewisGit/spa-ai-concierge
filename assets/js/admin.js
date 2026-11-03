/**
 * Spa AI Concierge meta box. Calls the plugin's REST routes with the wp_rest nonce and fills
 * the form fields with suggestions. Nothing is saved until the post is updated.
 */
( function () {
	'use strict';

	var config = window.spaAiConcierge || {};
	var __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : function ( s ) {
		return s;
	};
	var sprintf = window.wp && window.wp.i18n ? window.wp.i18n.sprintf : function ( s ) {
		return s;
	};

	function editorValues() {
		var values = { title: '', content: '', excerpt: '', featured: 0 };
		var data = window.wp && window.wp.data;
		var editor = data && data.select && data.select( 'core/editor' );
		if ( editor && typeof editor.getEditedPostAttribute === 'function' ) {
			values.title = editor.getEditedPostAttribute( 'title' ) || '';
			values.content = editor.getEditedPostContent ? editor.getEditedPostContent() : '';
			values.excerpt = editor.getEditedPostAttribute( 'excerpt' ) || '';
			values.featured = editor.getEditedPostAttribute( 'featured_media' ) || 0;
			return values;
		}
		var title = document.getElementById( 'title' );
		var excerpt = document.getElementById( 'excerpt' );
		var thumb = document.getElementById( '_thumbnail_id' );
		values.title = title ? title.value : '';
		values.excerpt = excerpt ? excerpt.value : '';
		if ( window.tinymce && window.tinymce.get( 'content' ) && ! window.tinymce.get( 'content' ).isHidden() ) {
			values.content = window.tinymce.get( 'content' ).getContent();
		} else {
			var content = document.getElementById( 'content' );
			values.content = content ? content.value : '';
		}
		values.featured = thumb ? parseInt( thumb.value, 10 ) || 0 : 0;
		return values;
	}

	function init( box ) {
		var postId = box.dataset.postId;
		var status = box.querySelector( '[data-spa-ai-status]' );
		var list = box.querySelector( '[data-spa-ai-faqs]' );
		var template = box.querySelector( '[data-spa-ai-faq-template]' );
		var source = box.querySelector( '[data-spa-ai-faq-source]' );
		var choices = box.querySelector( '[data-spa-ai-meta-choices]' );
		var rowIndex = list.children.length;

		function say( message, type ) {
			status.textContent = message;
			status.className = 'spa-ai__status' + ( type ? ' spa-ai__status--' + type : '' );
		}

		function counter( field ) {
			var limit = parseInt( field.dataset.spaAiCount, 10 );
			var out = field.parentNode.querySelector( '[data-spa-ai-counter]' );
			if ( ! out || ! limit ) {
				return;
			}
			var length = field.value.length;
			out.textContent = sprintf( __( '%1$d / %2$d characters', 'spa-ai-concierge' ), length, limit ) + ( length > limit ? ' · ' + __( 'may be cut off in search results', 'spa-ai-concierge' ) : '' );
			out.classList.toggle( 'is-over', length > limit );
		}

		function addRow( question, answer ) {
			var html = template.innerHTML.replace( /__i__/g, String( rowIndex++ ) );
			var holder = document.createElement( 'div' );
			holder.innerHTML = html.trim();
			var row = holder.firstElementChild;
			row.querySelector( '[data-spa-ai-q]' ).value = question || '';
			row.querySelector( '[data-spa-ai-a]' ).value = answer || '';
			list.appendChild( row );
			return row;
		}

		function request( path, body ) {
			return fetch( config.restRoot + postId + '/' + path, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce,
				},
				body: JSON.stringify( body ),
			} ).then( function ( response ) {
				return response.json().then(
					function ( json ) {
						if ( ! response.ok ) {
							throw new Error( ( json && json.message ) || __( 'Request failed.', 'spa-ai-concierge' ) );
						}
						return json;
					},
					function () {
						throw new Error( sprintf( __( 'Unexpected response from the server (HTTP %d).', 'spa-ai-concierge' ), response.status ) );
					}
				);
			} );
		}

		function suffix( result ) {
			return result.demo ? ' ' + __( '(Demo output: canned sample text, no API call.)', 'spa-ai-concierge' ) : '';
		}

		function renderChoices( data ) {
			choices.innerHTML = '';
			[
				[ 'titles', __( 'Title options', 'spa-ai-concierge' ), 'spa-ai-meta-title', data.limits.title ],
				[ 'descriptions', __( 'Description options', 'spa-ai-concierge' ), 'spa-ai-meta-desc', data.limits.description ],
			].forEach( function ( group ) {
				var fieldset = document.createElement( 'fieldset' );
				var legend = document.createElement( 'legend' );
				legend.textContent = group[ 1 ];
				fieldset.appendChild( legend );
				data[ group[ 0 ] ].forEach( function ( option, i ) {
					var label = document.createElement( 'label' );
					label.className = 'spa-ai__choice' + ( option.over ? ' is-over' : '' );
					var input = document.createElement( 'input' );
					input.type = 'radio';
					input.name = 'spa_ai_choice_' + group[ 0 ];
					input.value = option.text;
					input.addEventListener( 'change', function () {
						var target = document.getElementById( group[ 2 ] );
						target.value = option.text;
						counter( target );
					} );
					var text = document.createElement( 'span' );
					text.className = 'spa-ai__choice-text';
					text.textContent = option.text;
					var meta = document.createElement( 'span' );
					meta.className = 'spa-ai__choice-count';
					meta.textContent = sprintf( __( '%1$d chars', 'spa-ai-concierge' ), option.length ) + ( option.over ? ' · ' + sprintf( __( 'over %d', 'spa-ai-concierge' ), group[ 3 ] ) : '' );
					label.appendChild( input );
					label.appendChild( text );
					label.appendChild( meta );
					fieldset.appendChild( label );
					if ( i === 0 ) {
						input.setAttribute( 'aria-describedby', 'spa-ai-meta-h' );
					}
				} );
				choices.appendChild( fieldset );
			} );
			choices.hidden = false;
		}

		box.addEventListener( 'click', function ( event ) {
			var run = event.target.closest( '[data-spa-ai-run]' );
			if ( run ) {
				var task = run.dataset.spaAiRun;
				var values = editorValues();
				var buttons = box.querySelectorAll( '[data-spa-ai-run]' );
				buttons.forEach( function ( b ) {
					b.disabled = true;
				} );
				run.setAttribute( 'aria-busy', 'true' );
				say( __( 'Working…', 'spa-ai-concierge' ) );

				request( task, {
					title: values.title,
					content: values.content,
					excerpt: values.excerpt,
					attachment_id: task === 'alt-text' ? values.featured : 0,
				} )
					.then( function ( result ) {
						if ( task === 'faqs' ) {
							list.querySelectorAll( '[data-spa-ai-faq]' ).forEach( function ( row ) {
								if ( ! row.querySelector( '[data-spa-ai-q]' ).value.trim() && ! row.querySelector( '[data-spa-ai-a]' ).value.trim() ) {
									row.remove();
								}
							} );
							var first = null;
							result.data.faqs.forEach( function ( faq ) {
								var row = addRow( faq.question, faq.answer );
								row.classList.add( 'is-new' );
								first = first || row;
							} );
							source.value = result.demo ? 'demo' : 'ai';
							say( sprintf( __( 'Added %d FAQs. Review and edit them, then update the post to save.', 'spa-ai-concierge' ), result.data.faqs.length ) + suffix( result ), 'ok' );
							if ( first ) {
								first.querySelector( '[data-spa-ai-q]' ).focus();
							}
						} else if ( task === 'meta' ) {
							renderChoices( result.data );
							say( __( 'Pick a title and a description, edit if needed, then update the post to save.', 'spa-ai-concierge' ) + suffix( result ), 'ok' );
							var firstChoice = choices.querySelector( 'input' );
							if ( firstChoice ) {
								firstChoice.focus();
							}
						} else {
							var alt = document.getElementById( 'spa-ai-alt' );
							alt.value = result.data.alt_text;
							counter( alt );
							var attachment = box.querySelector( '[data-spa-ai-alt-attachment]' );
							if ( values.featured ) {
								attachment.value = values.featured;
							}
							say( __( 'Alt text drafted. Check it against the image, then update the post to save.', 'spa-ai-concierge' ) + suffix( result ), 'ok' );
							alt.focus();
						}
					} )
					.catch( function ( error ) {
						say( error.message, 'error' );
					} )
					.finally( function () {
						buttons.forEach( function ( b ) {
							b.disabled = false;
						} );
						run.removeAttribute( 'aria-busy' );
					} );
				return;
			}

			if ( event.target.closest( '[data-spa-ai-add-faq]' ) ) {
				addRow( '', '' ).querySelector( '[data-spa-ai-q]' ).focus();
				return;
			}

			var row = event.target.closest( '[data-spa-ai-faq]' );
			if ( ! row ) {
				return;
			}
			if ( event.target.closest( '[data-spa-ai-remove]' ) ) {
				var focusTarget = row.nextElementSibling || row.previousElementSibling;
				row.remove();
				if ( focusTarget ) {
					focusTarget.querySelector( '[data-spa-ai-q]' ).focus();
				} else {
					box.querySelector( '[data-spa-ai-add-faq]' ).focus();
				}
				return;
			}
			var move = event.target.closest( '[data-spa-ai-move]' );
			if ( move ) {
				if ( move.dataset.spaAiMove === 'up' && row.previousElementSibling ) {
					list.insertBefore( row, row.previousElementSibling );
				} else if ( move.dataset.spaAiMove === 'down' && row.nextElementSibling ) {
					list.insertBefore( row.nextElementSibling, row );
				}
				move.focus();
			}
		} );

		box.addEventListener( 'input', function ( event ) {
			if ( event.target.dataset && event.target.dataset.spaAiCount ) {
				counter( event.target );
			}
		} );
		box.querySelectorAll( '[data-spa-ai-count]' ).forEach( counter );
	}

	function boot() {
		document.querySelectorAll( '[data-spa-ai]' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
