/* global omefDashboard, wp */
(function () {
	'use strict';

	var cfg = window.omefDashboard || {};

	var NAV = [
		{ route: 'overview', label: 'סקירה כללית' },
		{ route: 'products', label: 'מוצרים' },
		{ route: 'orders', label: 'הזמנות' },
		{ route: 'shipping', label: 'משלוחים' },
		{ route: 'abandoned', label: 'עגלות נטושות' },
		{ route: 'leads', label: 'פניות' },
		{ route: 'emails', label: 'דוא"ל הזמנות' },
		{ route: 'backups', label: 'גיבויים' },
		{ route: 'redirects', label: 'הפניות 301' },
		{ route: 'podcast', label: 'פודקאסט' }
	];

	function escapeHtml( value ) {
		return String( value === null || value === undefined ? '' : value ).replace( /[&<>"']/g, function ( ch ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ ch ];
		} );
	}

	function money( amount ) {
		var n = Number( amount ) || 0;
		return n.toLocaleString( 'he-IL', { maximumFractionDigits: 0 } ) + ' ' + ( cfg.currencySign || '₪' );
	}

	function appendFormData( form, key, value ) {
		if ( Array.isArray( value ) ) {
			value.forEach( function ( item ) {
				form.append( key + '[]', item );
			} );
			return;
		}
		if ( typeof value === 'boolean' ) {
			if ( value ) {
				form.append( key, '1' );
			}
			return;
		}
		if ( value !== null && value !== undefined ) {
			form.append( key, value );
		}
	}

	function api( action, options ) {
		options = options || {};
		var method = options.method || 'GET';
		var data = options.data || {};

		if ( method === 'GET' ) {
			var url = new URL( cfg.ajaxUrl, window.location.href );
			url.searchParams.set( 'action', action );
			url.searchParams.set( 'nonce', cfg.nonce );
			Object.keys( data ).forEach( function ( key ) {
				if ( data[ key ] !== null && data[ key ] !== undefined && data[ key ] !== '' ) {
					url.searchParams.set( key, data[ key ] );
				}
			} );
			return fetch( url, { credentials: 'same-origin' } ).then( handleResponse );
		}

		var form = new FormData();
		form.append( 'action', action );
		form.append( 'nonce', cfg.nonce );
		Object.keys( data ).forEach( function ( key ) {
			appendFormData( form, key, data[ key ] );
		} );

		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form } ).then( handleResponse );
	}

	function handleResponse( res ) {
		return res.json().then( function ( json ) {
			if ( ! json.success ) {
				var message = ( json.data && json.data.message ) || 'אירעה שגיאה. נסו שוב.';
				throw new Error( message );
			}
			return json.data;
		} );
	}

	var toastTimer = null;
	function toast( message, type ) {
		var el = document.getElementById( 'omef-toast' );
		if ( ! el ) {
			return;
		}
		el.textContent = message;
		el.className = 'omef-toast omef-toast--' + ( type || 'success' ) + ' is-visible';
		el.hidden = false;
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () {
			el.classList.remove( 'is-visible' );
		}, 4000 );
	}

	function onError( err ) {
		toast( err.message || 'אירעה שגיאה.', 'error' );
	}

	/* ---------- shell ---------- */

	function renderShell() {
		var app = document.getElementById( 'omef-app' );
		app.innerHTML =
			'<div class="omef-shell">' +
				'<aside class="omef-sidebar">' +
					'<div class="omef-sidebar__brand">אומף</div>' +
					'<nav class="omef-nav" id="omef-nav">' +
						NAV.map( function ( item ) {
							return '<a href="#/' + item.route + '" data-route="' + item.route + '">' + item.label + '</a>';
						} ).join( '' ) +
					'</nav>' +
				'</aside>' +
				'<main class="omef-main">' +
					'<div class="omef-toast" id="omef-toast" hidden></div>' +
					'<div class="omef-view" id="omef-view"></div>' +
				'</main>' +
			'</div>';
	}

	var VIEWS = {
		overview: viewOverview,
		products: viewProducts,
		orders: viewOrders,
		shipping: viewShipping,
		abandoned: viewAbandoned,
		leads: viewLeads,
		emails: viewEmails,
		backups: viewBackups,
		redirects: viewRedirects,
		podcast: viewPodcast
	};

	function route() {
		var hash = window.location.hash.replace( /^#\/?/, '' );
		var parts = hash.split( '/' ).filter( Boolean );
		var section = parts[ 0 ] || 'overview';
		var param = parts[ 1 ];

		document.querySelectorAll( '#omef-nav a' ).forEach( function ( a ) {
			a.classList.toggle( 'is-active', a.dataset.route === section );
		} );

		var view = VIEWS[ section ] || viewOverview;
		var root = document.getElementById( 'omef-view' );
		root.innerHTML = '<div class="omef-loading">טוען…</div>';
		view( root, param );
	}

	/* ---------- overview ---------- */

	function viewOverview( root ) {
		api( 'omef_stats' ).then( function ( stats ) {
			root.innerHTML =
				'<h1 class="omef-view__title">סקירה כללית</h1>' +
				'<div class="omef-stats">' +
					statCard( 'הזמנות היום', stats.todayOrders ) +
					statCard( 'מחזור היום', money( stats.todayRevenue ) ) +
					statCard( 'בטיפול', stats.processingCount ) +
					statCard( 'אזל מהמלאי', stats.outOfStockCount ) +
					statCard( 'עגלות נטושות ממתינות', stats.abandonedPending ) +
					statCard( 'פניות שהתקבלו', stats.leadsCount ) +
					statCard( 'מוצרים במבצע', stats.discountCount ) +
					statCard( 'גיבוי אחרון', stats.lastBackup ? stats.lastBackup : 'אין עדיין' ) +
				'</div>' +
				'<h2 class="omef-view__subtitle">קישורים מהירים</h2>' +
				'<div class="omef-quicklinks">' +
					quickLink( 'products', 'מוצרים', 'ניהול קטלוג, מלאי ומחירים' ) +
					quickLink( 'orders', 'הזמנות', 'מעקב וטיפול בהזמנות' ) +
					quickLink( 'shipping', 'משלוחים', 'אזורי משלוח ושיטות' ) +
					quickLink( 'abandoned', 'עגלות נטושות', 'שליחת תזכורות' ) +
					quickLink( 'leads', 'פניות', 'פניות מטופס יצירת הקשר' ) +
					quickLink( 'emails', 'דוא"ל הזמנות', 'התאמת הודעות ללקוחות' ) +
					quickLink( 'backups', 'גיבויים', 'גיבוי והורדה של בסיס הנתונים' ) +
					quickLink( 'redirects', 'הפניות 301', 'ניהול הפניות מנתיבים ישנים' ) +
					quickLink( 'podcast', 'פודקאסט', 'ייבוא פרקים מהפיד' ) +
				'</div>';
		} ).catch( function ( err ) {
			root.innerHTML = errorBlock( err );
		} );
	}

	function statCard( label, value ) {
		return '<div class="omef-stat"><div class="omef-stat__value">' + escapeHtml( value ) + '</div><div class="omef-stat__label">' + escapeHtml( label ) + '</div></div>';
	}

	function quickLink( route, title, desc ) {
		return '<a class="omef-quicklink" href="#/' + route + '"><strong>' + escapeHtml( title ) + '</strong><span>' + escapeHtml( desc ) + '</span></a>';
	}

	function errorBlock( err ) {
		return '<div class="omef-empty omef-empty--error">' + escapeHtml( err.message || 'אירעה שגיאה.' ) + '</div>';
	}

	/* ---------- products ---------- */

	function viewProducts( root, param ) {
		if ( param ) {
			renderProductForm( root, param === 'new' ? null : param );
			return;
		}
		renderProductList( root );
	}

	function renderProductList( root, filters ) {
		filters = filters || {};
		api( 'omef_products_list', { data: filters } ).then( function ( result ) {
			root.innerHTML =
				'<div class="omef-view__header">' +
					'<h1 class="omef-view__title">מוצרים</h1>' +
					'<a class="omef-button omef-button--primary" href="#/products/new">מוצר חדש</a>' +
				'</div>' +
				'<div class="omef-filters">' +
					'<input type="search" id="omef-product-search" placeholder="חיפוש מוצר…" value="' + escapeHtml( filters.search || '' ) + '">' +
					'<label><input type="checkbox" id="omef-product-onsale" ' + ( filters.on_sale === '1' ? 'checked' : '' ) + '> במבצע בלבד</label>' +
				'</div>' +
				'<div class="omef-table-wrap"><table class="omef-table"><thead><tr>' +
					'<th></th><th>מוצר</th><th>מחיר</th><th>מלאי</th><th>קטגוריות</th><th></th>' +
				'</tr></thead><tbody>' +
				( result.products.length ? result.products.map( productRow ).join( '' ) : '<tr><td colspan="6" class="omef-empty">אין מוצרים תואמים.</td></tr>' ) +
				'</tbody></table></div>';

			root.querySelector( '#omef-product-search' ).addEventListener( 'change', function ( e ) {
				renderProductList( root, Object.assign( {}, filters, { search: e.target.value } ) );
			} );
			root.querySelector( '#omef-product-onsale' ).addEventListener( 'change', function ( e ) {
				renderProductList( root, Object.assign( {}, filters, { on_sale: e.target.checked ? '1' : '' } ) );
			} );
			root.querySelectorAll( '[data-product-row]' ).forEach( function ( row ) {
				bindProductRow( row, root, filters );
			} );
		} ).catch( function ( err ) {
			root.innerHTML = errorBlock( err );
		} );
	}

	function bindProductRow( row, root, filters ) {
		var deleteBtn = row.querySelector( '[data-delete-product]' );
		deleteBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( 'למחוק את המוצר לצמיתות?' ) ) {
				return;
			}
			api( 'omef_products_delete', { method: 'POST', data: { id: deleteBtn.dataset.deleteProduct } } )
				.then( function () {
					toast( 'המוצר נמחק.' );
					renderProductList( root, filters );
				} )
				.catch( onError );
		} );

		var saveBtn = row.querySelector( '[data-save-product]' );
		row.querySelectorAll( '.omef-inline-input' ).forEach( function ( input ) {
			input.addEventListener( 'input', function () {
				saveBtn.hidden = false;
			} );
			input.addEventListener( 'change', function () {
				saveBtn.hidden = false;
			} );
			input.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' ) {
					e.preventDefault();
					saveQuickEditRow( row, saveBtn, root, filters );
				}
			} );
		} );
		saveBtn.addEventListener( 'click', function () {
			saveQuickEditRow( row, saveBtn, root, filters );
		} );
	}

	function saveQuickEditRow( row, saveBtn, root, filters ) {
		var id = row.dataset.productRow;
		var data = {
			id: id,
			regular_price: row.querySelector( '[data-field="regular_price"]' ).value || 0,
			stock_quantity: row.querySelector( '[data-field="stock_quantity"]' ).value,
			stock_status: row.querySelector( '[data-field="stock_status"]' ).value
		};

		saveBtn.disabled = true;
		api( 'omef_products_quick_update', { method: 'POST', data: data } )
			.then( function ( result ) {
				var wrapper = document.createElement( 'tbody' );
				wrapper.innerHTML = productRow( result.product );
				var newRow = wrapper.firstElementChild;
				row.replaceWith( newRow );
				bindProductRow( newRow, root, filters );
				toast( 'המחיר והמלאי עודכנו.' );
			} )
			.catch( onError )
			.finally( function () {
				saveBtn.disabled = false;
			} );
	}

	var STOCK_STATUS_LABELS = { instock: 'במלאי', outofstock: 'אזל', onbackorder: 'בהזמנה מראש' };

	function productRow( p ) {
		var qtyValue = p.manageStock && p.stockQuantity !== null && p.stockQuantity !== undefined ? p.stockQuantity : '';
		return (
			'<tr data-product-row="' + p.id + '">' +
			'<td class="omef-table__thumb" data-label="">' + ( p.image ? '<img src="' + escapeHtml( p.image ) + '" alt="">' : '' ) + '</td>' +
			'<td data-label="מוצר"><a href="#/products/' + p.id + '">' + escapeHtml( p.title ) + '</a>' + ( p.onSale ? ' <span class="omef-badge omef-badge--sale">במבצע</span>' : '' ) + ( p.hasSample ? ' <span class="omef-badge">דגימה 30 מ"ל</span>' : '' ) + '</td>' +
			'<td data-label="מחיר"><input type="number" min="0" step="1" class="omef-inline-input omef-inline-input--price" data-field="regular_price" value="' + escapeHtml( p.regularPrice ) + '"></td>' +
			'<td data-label="מלאי"><div class="omef-inline-stock">' +
				'<input type="number" min="0" step="1" class="omef-inline-input omef-inline-input--qty" data-field="stock_quantity" placeholder="כמות" value="' + escapeHtml( qtyValue ) + '">' +
				'<select class="omef-inline-input" data-field="stock_status">' +
					Object.keys( STOCK_STATUS_LABELS ).map( function ( key ) {
						return '<option value="' + key + '"' + ( key === p.stockStatus ? ' selected' : '' ) + '>' + STOCK_STATUS_LABELS[ key ] + '</option>';
					} ).join( '' ) +
				'</select>' +
			'</div></td>' +
			'<td data-label="קטגוריות">' + escapeHtml( p.categories.join( ', ' ) ) + '</td>' +
			'<td class="omef-table__actions" data-label="">' +
				'<button class="omef-link-button omef-link-button--save" data-save-product="' + p.id + '" hidden>שמירה</button> ' +
				'<button class="omef-link-button omef-link-button--danger" data-delete-product="' + p.id + '">מחיקה</button>' +
			'</td>' +
			'</tr>'
		);
	}

	function renderProductForm( root, id ) {
		Promise.all( [
			id ? api( 'omef_products_get', { data: { id: id } } ) : Promise.resolve( emptyProduct() ),
			api( 'omef_categories_list' )
		] ).then( function ( results ) {
			var product = results[ 0 ];
			var categories = results[ 1 ].categories;

			root.innerHTML =
				'<div class="omef-view__header">' +
					'<h1 class="omef-view__title">' + ( id ? 'עריכת מוצר' : 'מוצר חדש' ) + '</h1>' +
					'<a class="omef-button" href="#/products">חזרה לרשימה</a>' +
				'</div>' +
				'<form class="omef-form" id="omef-product-form">' +
					formSection( 'פרטים כלליים',
						textField( 'title', 'שם המוצר', product.title, true ) +
						textareaField( 'short_description', 'תיאור קצר', product.shortDescription ) +
						textareaField( 'description', 'תיאור מלא', product.description ) +
						categoryField( categories, product.categoryIds )
					) +
					formSection( 'תמונה', imageField( product.imageId, product.imageUrl ) ) +
					formSection( 'מחיר ומלאי',
						numberField( 'regular_price', 'מחיר מלא (₪)', product.regularPrice, true ) +
						( product.nativeSaleSupported ? numberField( 'native_sale_price', 'מחיר מבצע של WooCommerce (₪, אופציונלי)', product.nativeSalePrice ) : '' ) +
						checkboxField( 'manage_stock', 'ניהול מלאי', product.manageStock ) +
						'<div class="omef-form__row omef-form__row--stock">' +
							numberField( 'stock_quantity', 'כמות במלאי', product.stockQuantity, false, true ) +
							selectField( 'stock_status', 'סטטוס מלאי', product.stockStatus, {
								instock: 'במלאי', outofstock: 'אזל', onbackorder: 'בהזמנה מראש'
							} ) +
						'</div>'
					) +
					formSection( 'פרטי הוויסקי',
						textField( 'omef[_omef_distillery]', 'מזקקה', product.omef._omef_distillery ) +
						textField( 'omef[_omef_region]', 'אזור', product.omef._omef_region ) +
						numberField( 'omef[_omef_age]', 'גיל (שנים)', product.omef._omef_age ) +
						numberField( 'omef[_omef_abv]', 'אחוז אלכוהול', product.omef._omef_abv ) +
						textField( 'omef[_omef_cask_type]', 'סוג חבית', product.omef._omef_cask_type ) +
						checkboxField( 'omef[_omef_peated]', 'מעושן', product.omef._omef_peated ) +
						textareaField( 'omef[_omef_tasting_notes]', 'הערות טעימה', product.omef._omef_tasting_notes )
					) +
					formSection( 'דגימה של 30 מ"ל',
						numberField( 'sample_price', 'מחיר דגימה (₪, אופציונלי — הפעלת השדה הופכת את המוצר למוצר עם וריאציות)', product.omef._omef_sample_price )
					) +
					formSection( 'מבצע עריכתי',
						numberField( 'omef[_omef_sale_price]', 'מחיר מבצע (₪, אופציונלי)', product.omef._omef_sale_price ) +
						textField( 'omef[_omef_sale_note]', 'סיבת המבצע', product.omef._omef_sale_note )
					) +
					'<div class="omef-form__actions">' +
						'<button type="submit" class="omef-button omef-button--primary">שמירה</button>' +
					'</div>' +
				'</form>';

			var mediaFrame = null;
			root.querySelector( '[data-image-picker]' ).addEventListener( 'click', function () {
				if ( ! window.wp || ! wp.media ) {
					return;
				}
				mediaFrame = wp.media( { title: 'בחירת תמונה ראשית', multiple: false, library: { type: 'image' } } );
				mediaFrame.on( 'select', function () {
					var att = mediaFrame.state().get( 'selection' ).first().toJSON();
					root.querySelector( '[name="image_id"]' ).value = att.id;
					var preview = root.querySelector( '[data-image-preview]' );
					preview.innerHTML = '<img src="' + escapeHtml( att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url ) + '" alt="">';
				} );
				mediaFrame.open();
			} );

			root.querySelector( '#omef-product-form' ).addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var formEl = e.target;
				var data = collectFormData( formEl, categories, product.categoryIds );
				if ( id ) {
					data.id = id;
				}
				var submitBtn = formEl.querySelector( 'button[type="submit"]' );
				submitBtn.disabled = true;
				api( 'omef_products_save', { method: 'POST', data: data } )
					.then( function ( result ) {
						toast( result.warning || 'המוצר נשמר.' , result.warning ? 'error' : 'success' );
						window.location.hash = '#/products/' + result.product.id;
						if ( id === String( result.product.id ) ) {
							renderProductForm( root, id );
						}
					} )
					.catch( onError )
					.finally( function () {
						submitBtn.disabled = false;
					} );
			} );
		} ).catch( function ( err ) {
			root.innerHTML = errorBlock( err );
		} );
	}

	function emptyProduct() {
		return {
			title: '', description: '', shortDescription: '', imageId: 0, imageUrl: '', categoryIds: [],
			regularPrice: 0, nativeSalePrice: 0, nativeSaleSupported: true, manageStock: true, stockQuantity: 0, stockStatus: 'instock',
			omef: { _omef_distillery: '', _omef_region: '', _omef_age: '', _omef_abv: '', _omef_cask_type: '', _omef_peated: false, _omef_tasting_notes: '', _omef_sample_price: '', _omef_sale_price: '', _omef_sale_note: '' }
		};
	}

	function collectFormData( formEl, categories, selectedCategoryIds ) {
		var fd = new FormData( formEl );
		var data = {
			title: fd.get( 'title' ) || '',
			short_description: fd.get( 'short_description' ) || '',
			description: fd.get( 'description' ) || '',
			image_id: fd.get( 'image_id' ) || 0,
			regular_price: fd.get( 'regular_price' ) || 0,
			native_sale_price: fd.get( 'native_sale_price' ) || '',
			manage_stock: !! fd.get( 'manage_stock' ),
			stock_quantity: fd.get( 'stock_quantity' ) || 0,
			stock_status: fd.get( 'stock_status' ) || 'instock',
			sample_price: fd.get( 'sample_price' ) || 0,
			category_ids: fd.getAll( 'category_ids[]' )
		};
		[ '_omef_distillery', '_omef_region', '_omef_age', '_omef_abv', '_omef_cask_type', '_omef_tasting_notes', '_omef_sale_price', '_omef_sale_note' ].forEach( function ( key ) {
			data[ key ] = fd.get( 'omef[' + key + ']' ) || '';
		} );
		data._omef_peated = !! fd.get( 'omef[_omef_peated]' );
		return data;
	}

	/* ---------- form field helpers ---------- */

	function formSection( title, body ) {
		return '<fieldset class="omef-form__section"><legend>' + escapeHtml( title ) + '</legend>' + body + '</fieldset>';
	}

	function textField( name, label, value, required ) {
		return '<label class="omef-field"><span>' + escapeHtml( label ) + '</span><input type="text" name="' + name + '" value="' + escapeHtml( value || '' ) + '" ' + ( required ? 'required' : '' ) + '></label>';
	}

	function numberField( name, label, value, required, half ) {
		return '<label class="omef-field' + ( half ? ' omef-field--half' : '' ) + '"><span>' + escapeHtml( label ) + '</span><input type="number" step="0.01" min="0" name="' + name + '" value="' + escapeHtml( value || '' ) + '" ' + ( required ? 'required' : '' ) + '></label>';
	}

	function textareaField( name, label, value ) {
		return '<label class="omef-field omef-field--wide"><span>' + escapeHtml( label ) + '</span><textarea name="' + name + '" rows="4">' + escapeHtml( value || '' ) + '</textarea></label>';
	}

	function checkboxField( name, label, checked ) {
		return '<label class="omef-field omef-field--checkbox"><input type="checkbox" name="' + name + '" ' + ( checked ? 'checked' : '' ) + '> ' + escapeHtml( label ) + '</label>';
	}

	function selectField( name, label, value, options ) {
		var opts = Object.keys( options ).map( function ( key ) {
			return '<option value="' + key + '" ' + ( key === value ? 'selected' : '' ) + '>' + escapeHtml( options[ key ] ) + '</option>';
		} ).join( '' );
		return '<label class="omef-field omef-field--half"><span>' + escapeHtml( label ) + '</span><select name="' + name + '">' + opts + '</select></label>';
	}

	function categoryField( categories, selectedIds ) {
		selectedIds = selectedIds || [];
		var boxes = categories.map( function ( c ) {
			return '<label class="omef-checkbox-chip"><input type="checkbox" name="category_ids[]" value="' + c.id + '" ' + ( selectedIds.indexOf( c.id ) !== -1 ? 'checked' : '' ) + '> ' + escapeHtml( c.name ) + '</label>';
		} ).join( '' );
		return '<div class="omef-field omef-field--wide"><span>קטגוריות</span><div class="omef-checkbox-group">' + boxes + '</div></div>';
	}

	function imageField( imageId, imageUrl ) {
		return (
			'<input type="hidden" name="image_id" value="' + ( imageId || 0 ) + '">' +
			'<div class="omef-image-picker">' +
				'<div class="omef-image-picker__preview" data-image-preview>' + ( imageUrl ? '<img src="' + escapeHtml( imageUrl ) + '" alt="">' : '<span>אין תמונה</span>' ) + '</div>' +
				'<button type="button" class="omef-button" data-image-picker>בחירת תמונה</button>' +
			'</div>'
		);
	}

	/* ---------- orders ---------- */

	function viewOrders( root, param ) {
		if ( param ) {
			renderOrderDetail( root, param );
			return;
		}
		renderOrderList( root );
	}

	function renderOrderList( root, filters ) {
		filters = filters || {};
		api( 'omef_orders_list', { data: filters } ).then( function ( result ) {
			var statusOptions = '<option value="">כל הסטטוסים</option>' + Object.keys( result.statuses ).map( function ( key ) {
				var short = key.replace( 'wc-', '' );
				return '<option value="' + short + '" ' + ( filters.status === short ? 'selected' : '' ) + '>' + escapeHtml( result.statuses[ key ] ) + '</option>';
			} ).join( '' );

			root.innerHTML =
				'<h1 class="omef-view__title">הזמנות</h1>' +
				'<div class="omef-filters">' +
					'<input type="search" id="omef-order-search" placeholder="חיפוש לפי שם/דוא&quot;ל…" value="' + escapeHtml( filters.search || '' ) + '">' +
					'<select id="omef-order-status">' + statusOptions + '</select>' +
				'</div>' +
				'<div class="omef-table-wrap"><table class="omef-table"><thead><tr>' +
					'<th>הזמנה</th><th>תאריך</th><th>לקוח</th><th>סטטוס</th><th>סכום</th>' +
				'</tr></thead><tbody>' +
				( result.orders.length ? result.orders.map( orderRow ).join( '' ) : '<tr><td colspan="5" class="omef-empty">אין הזמנות תואמות.</td></tr>' ) +
				'</tbody></table></div>';

			root.querySelector( '#omef-order-search' ).addEventListener( 'change', function ( e ) {
				renderOrderList( root, Object.assign( {}, filters, { search: e.target.value } ) );
			} );
			root.querySelector( '#omef-order-status' ).addEventListener( 'change', function ( e ) {
				renderOrderList( root, Object.assign( {}, filters, { status: e.target.value } ) );
			} );
		} ).catch( function ( err ) {
			root.innerHTML = errorBlock( err );
		} );
	}

	function orderRow( o ) {
		return (
			'<tr>' +
			'<td data-label="הזמנה"><a href="#/orders/' + o.id + '">#' + escapeHtml( o.orderNumber ) + '</a></td>' +
			'<td data-label="תאריך">' + escapeHtml( o.date ) + '</td>' +
			'<td data-label="לקוח">' + escapeHtml( o.customerName || o.customerEmail ) + '</td>' +
			'<td data-label="סטטוס"><span class="omef-badge">' + escapeHtml( o.statusLabel ) + '</span></td>' +
			'<td data-label="סכום">' + o.total + '</td>' +
			'</tr>'
		);
	}

	function renderOrderDetail( root, id ) {
		api( 'omef_orders_get', { data: { id: id } } ).then( function ( o ) {
			var statusOptions = Object.keys( o.statuses ).map( function ( key ) {
				var short = key.replace( 'wc-', '' );
				return '<option value="' + short + '" ' + ( short === o.status ? 'selected' : '' ) + '>' + escapeHtml( o.statuses[ key ] ) + '</option>';
			} ).join( '' );

			var itemsHtml = o.items.map( function ( item ) {
				return '<tr><td>' + escapeHtml( item.name ) + ' × ' + item.quantity + '</td><td>' + item.total + '</td></tr>';
			} ).join( '' );

			root.innerHTML =
				'<div class="omef-view__header">' +
					'<h1 class="omef-view__title">הזמנה #' + escapeHtml( o.orderNumber ) + '</h1>' +
					'<a class="omef-button" href="#/orders">חזרה לרשימה</a>' +
				'</div>' +
				'<div class="omef-order-detail">' +
					'<div class="omef-panel">' +
						'<h2>פריטים</h2>' +
						'<table class="omef-table"><tbody>' + itemsHtml + '</tbody></table>' +
						'<p><strong>משלוח:</strong> ' + o.shippingTotal + '</p>' +
						'<p><strong>סה"כ:</strong> ' + o.total + '</p>' +
						'<p><strong>אמצעי תשלום:</strong> ' + escapeHtml( o.paymentMethod ) + '</p>' +
						( o.customerNote ? '<p><strong>הערת לקוח:</strong> ' + escapeHtml( o.customerNote ) + '</p>' : '' ) +
					'</div>' +
					'<div class="omef-panel">' +
						'<h2>לקוח</h2>' +
						'<p>' + escapeHtml( o.customerName ) + '<br>' + escapeHtml( o.customerEmail ) + ( o.customerPhone ? '<br>' + escapeHtml( o.customerPhone ) : '' ) + '</p>' +
						'<h3>כתובת לחיוב</h3><p>' + o.billingAddress + '</p>' +
						( o.shippingAddress ? '<h3>כתובת למשלוח</h3><p>' + o.shippingAddress + '</p>' : '' ) +
					'</div>' +
					'<div class="omef-panel">' +
						'<h2>סטטוס</h2>' +
						'<label class="omef-field"><select id="omef-order-status-select">' + statusOptions + '</select></label>' +
						'<div class="omef-form__actions">' +
							'<button class="omef-button omef-button--primary" id="omef-order-status-save">עדכון סטטוס</button>' +
						'</div>' +
						'<div class="omef-form__actions">' +
							'<button class="omef-button" id="omef-order-resend">שליחת הודעה מחדש ללקוח</button>' +
						'</div>' +
						'<p class="omef-note"><a href="' + escapeHtml( o.editUrl ) + '" target="_blank" rel="noopener">זיכויים והחזרים מנוהלים במסך ה-WooCommerce המקורי ↗</a></p>' +
					'</div>' +
				'</div>';

			root.querySelector( '#omef-order-status-save' ).addEventListener( 'click', function () {
				var status = root.querySelector( '#omef-order-status-select' ).value;
				api( 'omef_orders_update_status', { method: 'POST', data: { id: id, status: status } } )
					.then( function () {
						toast( 'סטטוס ההזמנה עודכן.' );
						renderOrderDetail( root, id );
					} )
					.catch( onError );
			} );

			root.querySelector( '#omef-order-resend' ).addEventListener( 'click', function ( e ) {
				e.target.disabled = true;
				api( 'omef_orders_resend_email', { method: 'POST', data: { id: id } } )
					.then( function () {
						toast( 'ההודעה נשלחה מחדש.' );
					} )
					.catch( onError )
					.finally( function () {
						e.target.disabled = false;
					} );
			} );
		} ).catch( function ( err ) {
			root.innerHTML = errorBlock( err );
		} );
	}

	/* ---------- shipping ---------- */

	function viewShipping( root, param ) {
		if ( param ) {
			renderShippingMethodForm( root, param );
			return;
		}
		renderShippingZones( root );
	}

	function renderShippingZones( root ) {
		Promise.all( [ api( 'omef_shipping_zones_list' ), api( 'omef_shipping_countries_list' ) ] ).then( function ( results ) {
			var zones = results[ 0 ].zones;
			var countries = results[ 1 ].countries;
			var methodTypes = results[ 1 ].methodTypes;

			root.innerHTML =
				'<h1 class="omef-view__title">משלוחים</h1>' +
				'<p class="omef-note">אזורי משלוח נבדקים לפי הסדר; הראשון שמתאים לכתובת הלקוח הוא זה שחל. "כל העולם" הוא ברירת המחדל לכתובות שלא הותאמו לאזור ספציפי.</p>' +
				'<form class="omef-form omef-form--inline" id="omef-zone-form">' +
					textField( 'name', 'שם אזור חדש', '' ) +
					'<label class="omef-field omef-field--wide"><span>מדינות באזור</span><select name="countries" multiple size="6">' +
						countries.map( function ( c ) { return '<option value="' + escapeHtml( c.code ) + '">' + escapeHtml( c.name ) + '</option>'; } ).join( '' ) +
					'</select></label>' +
					'<button type="submit" class="omef-button omef-button--primary">יצירת אזור</button>' +
				'</form>' +
				'<div class="omef-zones">' +
				zones.map( function ( zone ) { return shippingZoneCard( zone, methodTypes ); } ).join( '' ) +
				'</div>';

			root.querySelector( '#omef-zone-form' ).addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var select = root.querySelector( 'select[name="countries"]' );
				var selected = Array.from( select.selectedOptions ).map( function ( o ) { return o.value; } );
				api( 'omef_shipping_zone_save', { method: 'POST', data: { name: new FormData( e.target ).get( 'name' ), countries: selected } } )
					.then( function () {
						toast( 'האזור נוצר.' );
						renderShippingZones( root );
					} )
					.catch( onError );
			} );

			root.querySelectorAll( '[data-add-method]' ).forEach( function ( select ) {
				select.addEventListener( 'change', function () {
					if ( ! select.value ) {
						return;
					}
					api( 'omef_shipping_method_add', { method: 'POST', data: { zone_id: select.dataset.addMethod, type: select.value } } )
						.then( function () {
							toast( 'שיטת המשלוח נוספה.' );
							renderShippingZones( root );
						} )
						.catch( onError );
				} );
			} );

			root.querySelectorAll( '[data-delete-zone]' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					if ( ! window.confirm( 'למחוק את אזור המשלוח?' ) ) {
						return;
					}
					api( 'omef_shipping_zone_delete', { method: 'POST', data: { id: btn.dataset.deleteZone } } )
						.then( function () {
							toast( 'האזור נמחק.' );
							renderShippingZones( root );
						} )
						.catch( onError );
				} );
			} );

			root.querySelectorAll( '[data-delete-method]' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					api( 'omef_shipping_method_delete', { method: 'POST', data: { zone_id: btn.dataset.zoneId, instance_id: btn.dataset.deleteMethod } } )
						.then( function () {
							toast( 'שיטת המשלוח נמחקה.' );
							renderShippingZones( root );
						} )
						.catch( onError );
				} );
			} );
		} ).catch( function ( err ) {
			root.innerHTML = errorBlock( err );
		} );
	}

	function shippingZoneCard( zone, methodTypes ) {
		var methodsHtml = zone.methods.length
			? zone.methods.map( function ( m ) {
				return (
					'<li class="omef-shipping-method">' +
						'<span class="omef-badge omef-badge--' + ( m.enabled ? 'ok' : 'warn' ) + '">' + ( m.enabled ? 'פעיל' : 'כבוי' ) + '</span> ' +
						'<a href="#/shipping/' + m.instanceId + '">' + escapeHtml( m.title || m.methodTitle ) + '</a>' +
						( m.cost !== '' && m.cost !== null ? ' — ' + money( m.cost ) : '' ) +
						' <button class="omef-link-button omef-link-button--danger" data-delete-method="' + m.instanceId + '" data-zone-id="' + zone.id + '">מחיקה</button>' +
					'</li>'
				);
			} ).join( '' )
			: '<li class="omef-empty">אין שיטות משלוח באזור זה.</li>';

		var addOptions = '<option value="">הוספת שיטת משלוח…</option>' + methodTypes.map( function ( t ) {
			return '<option value="' + escapeHtml( t.id ) + '">' + escapeHtml( t.title ) + '</option>';
		} ).join( '' );

		return (
			'<section class="omef-panel omef-zone-card">' +
				'<div class="omef-view__header">' +
					'<h2>' + escapeHtml( zone.name ) + '</h2>' +
					( zone.id ? '<button class="omef-link-button omef-link-button--danger" data-delete-zone="' + zone.id + '">מחיקת אזור</button>' : '' ) +
				'</div>' +
				'<p class="omef-note">' + escapeHtml( zone.location ) + '</p>' +
				'<ul class="omef-shipping-method-list">' + methodsHtml + '</ul>' +
				'<select data-add-method="' + zone.id + '">' + addOptions + '</select>' +
			'</section>'
		);
	}

	function renderShippingMethodForm( root, instanceId ) {
		api( 'omef_shipping_method_get', { data: { instance_id: instanceId } } ).then( function ( m ) {
			var fieldsHtml = m.fields.map( function ( f ) {
				if ( f.type === 'checkbox' ) {
					return checkboxField( f.key, f.label, f.value === 'yes' );
				}
				if ( f.type === 'select' && f.options ) {
					return selectField( f.key, f.label, f.value, f.options );
				}
				return textField( f.key, f.label, f.value ) + ( f.description ? '<p class="omef-note">' + escapeHtml( f.description ) + '</p>' : '' );
			} ).join( '' );

			root.innerHTML =
				'<div class="omef-view__header">' +
					'<h1 class="omef-view__title">' + escapeHtml( m.methodTitle ) + '</h1>' +
					'<a class="omef-button" href="#/shipping">חזרה למשלוחים</a>' +
				'</div>' +
				'<form class="omef-form" id="omef-method-form">' +
					checkboxField( 'enabled', 'שיטת משלוח פעילה', m.enabled ) +
					fieldsHtml +
					'<div class="omef-form__actions"><button type="submit" class="omef-button omef-button--primary">שמירה</button></div>' +
				'</form>';

			root.querySelector( '#omef-method-form' ).addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var fd = new FormData( e.target );
				var data = { instance_id: instanceId, enabled: !! fd.get( 'enabled' ) };
				m.fields.forEach( function ( f ) {
					data[ f.key ] = f.type === 'checkbox' ? !! fd.get( f.key ) : ( fd.get( f.key ) || '' );
				} );
				api( 'omef_shipping_method_save', { method: 'POST', data: data } )
					.then( function () {
						toast( 'ההגדרות נשמרו.' );
						window.location.hash = '#/shipping';
					} )
					.catch( onError );
			} );
		} ).catch( function ( err ) {
			root.innerHTML = errorBlock( err );
		} );
	}

	/* ---------- abandoned carts ---------- */

	function viewAbandoned( root ) {
		api( 'omef_abandoned_list' ).then( function ( result ) {
			root.innerHTML =
				'<div class="omef-view__header">' +
					'<h1 class="omef-view__title">עגלות נטושות</h1>' +
					'<button class="omef-button omef-button--primary" id="omef-abandoned-remind">שליחת תזכורות עכשיו</button>' +
				'</div>' +
				'<p class="omef-note">תזכורת נשלחת אוטומטית 24 שעות לאחר נטישה, פעם אחת בלבד לכל עגלה.</p>' +
				'<div class="omef-table-wrap"><table class="omef-table"><thead><tr>' +
					'<th>דוא"ל</th><th>נוצרה</th><th>סטטוס</th>' +
				'</tr></thead><tbody>' +
				( result.carts.length ? result.carts.map( function ( c ) {
					return '<tr><td data-label="דוא&quot;ל">' + escapeHtml( c.email ) + '</td><td data-label="נוצרה">' + escapeHtml( c.createdAt ) + '</td><td data-label="סטטוס"><span class="omef-badge">' + escapeHtml( c.status ) + '</span></td></tr>';
				} ).join( '' ) : '<tr><td colspan="3" class="omef-empty">אין עגלות נטושות.</td></tr>' ) +
				'</tbody></table></div>';

			root.querySelector( '#omef-abandoned-remind' ).addEventListener( 'click', function ( e ) {
				e.target.disabled = true;
				api( 'omef_abandoned_remind', { method: 'POST' } )
					.then( function ( r ) {
						toast( 'נשלחו ' + r.sent + ' תזכורות.' );
						viewAbandoned( root );
					} )
					.catch( onError )
					.finally( function () {
						e.target.disabled = false;
					} );
			} );
		} ).catch( function ( err ) {
			root.innerHTML = errorBlock( err );
		} );
	}

	/* ---------- leads ---------- */

	function viewLeads( root ) {
		api( 'omef_leads_list' ).then( function ( result ) {
			root.innerHTML =
				'<h1 class="omef-view__title">פניות</h1>' +
				'<p class="omef-note">פניות שהתקבלו דרך טופס יצירת הקשר באתר.</p>' +
				'<div class="omef-table-wrap"><table class="omef-table"><thead><tr>' +
					'<th>שם</th><th>טלפון</th><th>מעוניין/ת ב</th><th>התקבלה</th>' +
				'</tr></thead><tbody>' +
				( result.leads.length ? result.leads.map( function ( l ) {
					return '<tr>' +
						'<td data-label="שם">' + escapeHtml( l.name ) + '</td>' +
						'<td data-label="טלפון">' + escapeHtml( l.phone ) + '</td>' +
						'<td data-label="מעוניין/ת ב">' + escapeHtml( l.interest ) + '</td>' +
						'<td data-label="התקבלה">' + escapeHtml( l.createdAt ) + '</td>' +
					'</tr>';
				} ).join( '' ) : '<tr><td colspan="4" class="omef-empty">אין עדיין פניות.</td></tr>' ) +
				'</tbody></table></div>';
		} ).catch( function ( err ) {
			root.innerHTML = errorBlock( err );
		} );
	}

	/* ---------- emails ---------- */

	function viewEmails( root ) {
		api( 'omef_email_settings_get' ).then( function ( s ) {
			root.innerHTML =
				'<h1 class="omef-view__title">דוא"ל הזמנות</h1>' +
				'<form class="omef-form" id="omef-email-form">' +
					formSection( 'הודעה למנהל על כל רכישה',
						checkboxField( 'admin_notification_enabled', 'שליחת הודעה למנהל', s.adminNotificationEnabled ) +
						textField( 'admin_notification_to', 'כתובות למנהל (מופרדות בפסיק)', s.adminNotificationTo )
					) +
					formSection( 'הודעת לקוח',
						checkboxField( 'customer_email_enabled', 'שליחת הודעות ללקוחות', s.customerEmailEnabled ) +
						textField( 'customer_email_subject', 'שורת הנושא', s.customerEmailSubject ) +
						textareaField( 'customer_email_template', 'תוכן ההודעה (HTML)', s.customerEmailTemplate ) +
						'<p class="omef-note">משתנים זמינים: ' + s.placeholders.map( escapeHtml ).join( ', ' ) + '</p>'
					) +
					'<div class="omef-form__actions"><button type="submit" class="omef-button omef-button--primary">שמירה</button></div>' +
				'</form>';

			var textarea = root.querySelector( '[name="customer_email_template"]' );
			if ( textarea ) {
				textarea.rows = 14;
			}

			root.querySelector( '#omef-email-form' ).addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var fd = new FormData( e.target );
				api( 'omef_email_settings_save', {
					method: 'POST',
					data: {
						admin_notification_enabled: !! fd.get( 'admin_notification_enabled' ),
						admin_notification_to: fd.get( 'admin_notification_to' ) || '',
						customer_email_enabled: !! fd.get( 'customer_email_enabled' ),
						customer_email_subject: fd.get( 'customer_email_subject' ) || '',
						customer_email_template: fd.get( 'customer_email_template' ) || ''
					}
				} ).then( function () {
					toast( 'ההגדרות נשמרו.' );
				} ).catch( onError );
			} );
		} ).catch( function ( err ) {
			root.innerHTML = errorBlock( err );
		} );
	}

	/* ---------- backups ---------- */

	function viewBackups( root ) {
		api( 'omef_backups_list' ).then( function ( result ) {
			root.innerHTML =
				'<div class="omef-view__header">' +
					'<h1 class="omef-view__title">גיבויים</h1>' +
					'<button class="omef-button omef-button--primary" id="omef-backup-run">גיבוי עכשיו</button>' +
				'</div>' +
				'<p class="omef-note">גיבוי דחוס נוצר אוטומטית מדי יום; 14 הגיבויים האחרונים נשמרים ב-<code>wp-content/uploads/omef-backups</code>.</p>' +
				'<div class="omef-table-wrap"><table class="omef-table"><thead><tr><th>קובץ</th><th>גודל</th><th></th></tr></thead><tbody>' +
				( result.backups.length ? result.backups.map( function ( b ) {
					return '<tr><td data-label="קובץ"><code>' + escapeHtml( b.name ) + '</code></td><td data-label="גודל">' + ( b.size / 1024 / 1024 ).toFixed( 1 ) + ' MB</td><td data-label=""><a href="' + escapeHtml( b.downloadUrl ) + '">הורדה</a></td></tr>';
				} ).join( '' ) : '<tr><td colspan="3" class="omef-empty">אין גיבויים עדיין.</td></tr>' ) +
				'</tbody></table></div>';

			root.querySelector( '#omef-backup-run' ).addEventListener( 'click', function ( e ) {
				e.target.disabled = true;
				api( 'omef_backups_run', { method: 'POST' } )
					.then( function ( r ) {
						toast( r.message );
						viewBackups( root );
					} )
					.catch( onError )
					.finally( function () {
						e.target.disabled = false;
					} );
			} );
		} ).catch( function ( err ) {
			root.innerHTML = errorBlock( err );
		} );
	}

	/* ---------- redirects ---------- */

	function viewRedirects( root ) {
		api( 'omef_redirects_list' ).then( function ( result ) {
			root.innerHTML =
				'<h1 class="omef-view__title">הפניות 301</h1>' +
				'<form class="omef-form omef-form--inline" id="omef-redirect-form">' +
					textField( 'source', 'נתיב ישן', '' ) +
					textField( 'target', 'יעד', '' ) +
					'<button type="submit" class="omef-button omef-button--primary">הוספה</button>' +
				'</form>' +
				'<div class="omef-table-wrap"><table class="omef-table"><thead><tr><th>מקור</th><th>יעד</th><th>מצב</th><th></th></tr></thead><tbody>' +
				( result.redirects.length ? result.redirects.map( function ( r ) {
					return '<tr><td data-label="מקור"><code>/' + escapeHtml( r.source ) + '</code></td><td data-label="יעד"><code>/' + escapeHtml( r.target ) + '</code></td><td data-label="מצב">' + ( r.active ? 'פעילה' : 'מושבתת' ) + '</td><td data-label=""><button class="omef-link-button omef-link-button--danger" data-delete-redirect="' + r.id + '">מחיקה</button></td></tr>';
				} ).join( '' ) : '<tr><td colspan="4" class="omef-empty">אין הפניות.</td></tr>' ) +
				'</tbody></table></div>';

			root.querySelector( '#omef-redirect-form' ).addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var fd = new FormData( e.target );
				api( 'omef_redirects_add', { method: 'POST', data: { source: fd.get( 'source' ), target: fd.get( 'target' ) } } )
					.then( function () {
						toast( 'ההפנייה נשמרה.' );
						viewRedirects( root );
					} )
					.catch( onError );
			} );

			root.querySelectorAll( '[data-delete-redirect]' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					api( 'omef_redirects_delete', { method: 'POST', data: { id: btn.dataset.deleteRedirect } } )
						.then( function () {
							toast( 'ההפנייה נמחקה.' );
							viewRedirects( root );
						} )
						.catch( onError );
				} );
			} );
		} ).catch( function ( err ) {
			root.innerHTML = errorBlock( err );
		} );
	}

	/* ---------- podcast ---------- */

	function viewPodcast( root ) {
		api( 'omef_podcast_settings_get' ).then( function ( s ) {
			var last = s.lastImport && s.lastImport.message ? '<p class="omef-note"><strong>ייבוא אחרון:</strong> ' + escapeHtml( s.lastImport.message ) + ' · ' + escapeHtml( s.lastImport.time || '' ) + '</p>' : '';

			root.innerHTML =
				'<h1 class="omef-view__title">ייבוא פודקאסט</h1>' +
				'<p class="omef-note">הייבוא יוצר פרקים כטיוטות בלבד. טקסט שכתבתם בפרק ובקבוקים שבחרתם אינם משתנים בייבוא חוזר.</p>' +
				'<form class="omef-form" id="omef-podcast-form">' +
					textField( 'feed_url', 'כתובת RSS', s.feedUrl ) +
					'<div class="omef-form__actions">' +
						'<button type="submit" class="omef-button omef-button--primary">שמירת כתובת</button>' +
						'<button type="button" class="omef-button" id="omef-podcast-import" ' + ( s.feedUrl ? '' : 'disabled' ) + '>ייבוא עכשיו</button>' +
					'</div>' +
				'</form>' +
				last;

			root.querySelector( '#omef-podcast-form' ).addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var fd = new FormData( e.target );
				api( 'omef_podcast_settings_save', { method: 'POST', data: { feed_url: fd.get( 'feed_url' ) } } )
					.then( function () {
						toast( 'כתובת הפיד נשמרה.' );
						viewPodcast( root );
					} )
					.catch( onError );
			} );

			var importBtn = root.querySelector( '#omef-podcast-import' );
			if ( importBtn ) {
				importBtn.addEventListener( 'click', function () {
					importBtn.disabled = true;
					api( 'omef_podcast_import', { method: 'POST' } )
						.then( function ( r ) {
							toast( r.message );
							viewPodcast( root );
						} )
						.catch( onError )
						.finally( function () {
							importBtn.disabled = false;
						} );
				} );
			}
		} ).catch( function ( err ) {
			root.innerHTML = errorBlock( err );
		} );
	}

	/* ---------- boot ---------- */

	document.addEventListener( 'DOMContentLoaded', function () {
		renderShell();
		route();
		window.addEventListener( 'hashchange', route );
	} );
})();
