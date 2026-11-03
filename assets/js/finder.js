/**
 * Treatment Finder: turns the server-rendered GET form into a one-question-at-a-time quiz and
 * loads results from the REST API. Without JavaScript the form submits normally and the
 * server renders the same results.
 */
( function () {
	'use strict';

	var i18n = window.spaAiFinder || {};
	var FIELDS = [ 'concern', 'area', 'downtime', 'budget' ];

	function push( payload ) {
		if ( window.dataLayer && typeof window.dataLayer.push === 'function' ) {
			window.dataLayer.push( payload );
		}
	}

	function format( template, a, b ) {
		return String( template || '' ).replace( '%1$d', a ).replace( '%2$d', b );
	}

	function init( root ) {
		var form = root.querySelector( '[data-spa-finder-form]' );
		var results = root.querySelector( '[data-spa-finder-results]' );
		if ( ! form || ! results || root.dataset.enhanced ) {
			return;
		}
		root.dataset.enhanced = '1';
		root.classList.add( 'is-enhanced' );
		form.noValidate = true;

		var steps = Array.prototype.slice.call( form.querySelectorAll( '.spa-finder__step' ) );
		var progress = form.querySelector( '[data-spa-finder-progress]' );
		var back = form.querySelector( '[data-spa-finder-back]' );
		var next = form.querySelector( '[data-spa-finder-next]' );
		var submit = form.querySelector( '[data-spa-finder-submit]' );
		var current = 0;
		var busy = false;

		function stepValue( step ) {
			var checked = step.querySelector( 'input[type="radio"]:checked' );
			return checked ? checked.value : '';
		}

		function setError( step, on ) {
			var error = step.querySelector( '[data-spa-finder-error]' );
			var errorId = root.id + '-error-' + step.dataset.step;
			if ( error ) {
				error.id = errorId;
				error.hidden = ! on;
			}
			step.querySelectorAll( 'input[type="radio"]' ).forEach( function ( input ) {
				if ( on ) {
					input.setAttribute( 'aria-invalid', 'true' );
					input.setAttribute( 'aria-describedby', errorId );
				} else {
					input.removeAttribute( 'aria-invalid' );
					input.removeAttribute( 'aria-describedby' );
				}
			} );
		}

		function validate( step ) {
			var ok = stepValue( step ) !== '';
			setError( step, ! ok );
			if ( ! ok ) {
				var first = step.querySelector( 'input[type="radio"]' );
				if ( first ) {
					first.focus();
				}
			}
			return ok;
		}

		function show( index, moveFocus ) {
			current = Math.max( 0, Math.min( steps.length - 1, index ) );
			steps.forEach( function ( step, i ) {
				step.hidden = i !== current;
			} );
			progress.hidden = false;
			progress.textContent = format( i18n.progress || 'Step %1$d of %2$d', current + 1, steps.length );
			progress.style.setProperty( '--spa-progress', ( ( current + 1 ) / steps.length ) * 100 + '%' );
			back.hidden = current === 0;
			next.hidden = current === steps.length - 1;
			submit.hidden = current !== steps.length - 1;
			if ( moveFocus ) {
				var question = steps[ current ].querySelector( '.spa-finder__question' );
				if ( question ) {
					question.focus();
				}
			}
		}

		function answers() {
			var data = {};
			FIELDS.forEach( function ( name ) {
				var checked = form.querySelector( 'input[name="' + name + '"]:checked' );
				data[ name ] = checked ? checked.value : '';
			} );
			return data;
		}

		function showResults( html ) {
			results.innerHTML = html;
			form.hidden = true;
			var wrap = results.querySelector( '[data-spa-finder-complete]' );
			var title = results.querySelector( '.spa-finder__results-title' );
			if ( title ) {
				title.focus();
			}
			if ( wrap ) {
				var titles = Array.prototype.map.call( results.querySelectorAll( '.spa-card__title' ), function ( node ) {
					return node.textContent.trim();
				} );
				push( {
					event: 'finder_complete',
					finder_concern: wrap.dataset.concern,
					finder_area: wrap.dataset.area,
					finder_downtime: wrap.dataset.downtime,
					finder_budget: wrap.dataset.budget,
					finder_result_count: parseInt( wrap.dataset.count, 10 ) || 0,
					finder_results: titles.join( ' | ' ),
				} );
			}
		}

		function restart() {
			form.reset();
			steps.forEach( function ( step ) {
				setError( step, false );
			} );
			results.innerHTML = '';
			form.hidden = false;
			if ( window.history && window.history.replaceState ) {
				var url = new URL( window.location.href );
				[ 'spa_finder' ].concat( FIELDS ).forEach( function ( key ) {
					url.searchParams.delete( key );
				} );
				window.history.replaceState( null, '', url.toString() );
			}
			show( 0, true );
		}

		back.addEventListener( 'click', function () {
			show( current - 1, true );
		} );

		next.addEventListener( 'click', function () {
			if ( validate( steps[ current ] ) ) {
				show( current + 1, true );
			}
		} );

		form.addEventListener( 'change', function ( event ) {
			var step = event.target.closest( '.spa-finder__step' );
			if ( step ) {
				setError( step, false );
			}
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( busy ) {
				return;
			}
			for ( var i = 0; i < steps.length; i++ ) {
				if ( stepValue( steps[ i ] ) === '' ) {
					show( i, false );
					validate( steps[ i ] );
					return;
				}
			}

			var data = answers();
			var endpoint = new URL( root.dataset.rest, window.location.href );
			FIELDS.forEach( function ( key ) {
				endpoint.searchParams.set( key, data[ key ] );
			} );

			busy = true;
			submit.disabled = true;
			root.setAttribute( 'aria-busy', 'true' );
			results.innerHTML = '<p class="spa-finder__loading">' + ( i18n.loading || 'Loading…' ) + '</p>';

			fetch( endpoint.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' } )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'HTTP ' + response.status );
					}
					return response.json();
				} )
				.then( function ( body ) {
					showResults( body.html || '' );
					if ( window.history && window.history.replaceState ) {
						var url = new URL( window.location.href );
						url.searchParams.set( 'spa_finder', '1' );
						FIELDS.forEach( function ( key ) {
							url.searchParams.set( key, data[ key ] );
						} );
						url.hash = root.id;
						window.history.replaceState( null, '', url.toString() );
					}
				} )
				.catch( function () {
					// Fall back to the plain GET submit, which renders results on the server.
					results.innerHTML = '';
					form.submit();
				} )
				.finally( function () {
					busy = false;
					submit.disabled = false;
					root.removeAttribute( 'aria-busy' );
				} );
		} );

		root.addEventListener( 'click', function ( event ) {
			var book = event.target.closest( '[data-spa-finder-book]' );
			if ( book ) {
				push( {
					event: 'finder_book_click',
					finder_treatment: book.dataset.treatment || '',
					finder_treatment_id: parseInt( book.dataset.treatmentId, 10 ) || 0,
				} );
				return;
			}
			var restartLink = event.target.closest( '[data-spa-finder-restart]' );
			if ( restartLink ) {
				event.preventDefault();
				restart();
			}
		} );

		// Results already rendered by the server (GET submit or shared link).
		if ( results.querySelector( '[data-spa-finder-complete]' ) ) {
			showResults( results.innerHTML );
			show( 0, false );
		} else {
			show( 0, false );
		}
	}

	function boot() {
		document.querySelectorAll( '[data-spa-finder]' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
