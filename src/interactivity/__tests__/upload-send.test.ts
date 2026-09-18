/**
 * @jest-environment jsdom
 */

import {
	actionOf,
	percentOf,
	SEND_TIMEOUT_MS,
	sendWithProgress,
} from '../shared/upload-send';

describe( 'percentOf', () => {
	it( 'rounds to a whole percent', () => {
		expect( percentOf( 0, 200 ) ).toBe( 0 );
		expect( percentOf( 128, 200 ) ).toBe( 64 );
		expect( percentOf( 199, 200 ) ).toBe( 100 );
		expect( percentOf( 200, 200 ) ).toBe( 100 );
	} );

	it( 'never leaves 0–100, and says nothing when the size is unknown', () => {
		// Browsers report a total of zero when the length is not computable.
		expect( percentOf( 500, 0 ) ).toBe( 0 );
		expect( percentOf( 300, 200 ) ).toBe( 100 );
		expect( percentOf( -5, 200 ) ).toBe( 0 );
		expect( percentOf( Number.NaN, 200 ) ).toBe( 0 );
		expect( percentOf( 10, Number.POSITIVE_INFINITY ) ).toBe( 0 );
	} );
} );

/**
 * A stand-in request that records what was sent and lets a test drive it.
 */
class FakeRequest {
	public upload = new EventTarget();
	public responseURL = '';
	public opened: [ string, string ] | null = null;
	public body: FormData | null = null;
	public timeout = 0;
	public aborted = 0;
	private listeners = new EventTarget();

	abort(): void {
		this.aborted++;
		this.emit( 'abort' );
	}

	open( method: string, url: string ): void {
		this.opened = [ method, url ];
	}

	send( body: FormData ): void {
		this.body = body;
	}

	addEventListener( type: string, listener: EventListener ): void {
		this.listeners.addEventListener( type, listener );
	}

	emit( type: string ): void {
		this.listeners.dispatchEvent( new Event( type ) );
	}

	progress( loaded: number, total: number ): void {
		const event = new Event( 'progress' ) as ProgressEvent;

		Object.assign( event, { loaded, total, lengthComputable: total > 0 } );
		this.upload.dispatchEvent( event );
	}
}

describe( 'actionOf', () => {
	it( 'reads the attribute, which a field named "action" cannot shadow', () => {
		document.body.innerHTML = `
			<form action="/wp-admin/admin-post.php" method="post">
				<input type="hidden" name="action" value="aggr_upload_creative">
			</form>`;

		const form = document.querySelector( 'form' ) as HTMLFormElement;

		/*
		 * What a browser does and jsdom does not: the named control replaces
		 * the property. Recreated here so this test fails the way the page
		 * did, rather than passing because jsdom is kinder.
		 */
		Object.defineProperty( form, 'action', {
			value: form.querySelector( 'input[name="action"]' ),
		} );

		expect( actionOf( form ) ).toBe(
			new URL( '/wp-admin/admin-post.php', document.baseURI ).href
		);
	} );
} );

describe( 'sendWithProgress', () => {
	function form(): HTMLFormElement {
		document.body.innerHTML = `
			<form action="https://site.test/wp-admin/admin-post.php" method="post" enctype="multipart/form-data">
				<input type="hidden" name="action" value="aggr_upload_creative">
				<input type="hidden" name="_wpnonce" value="abc">
				<input type="url" name="click_url" value="https://example.com/">
			</form>`;

		return document.querySelector( 'form' ) as HTMLFormElement;
	}

	function handlers() {
		return { progress: jest.fn(), done: jest.fn(), fail: jest.fn() };
	}

	it( 'posts every field of the form to its own action', () => {
		const request = new FakeRequest();
		const on = handlers();

		expect(
			sendWithProgress(
				form(),
				on,
				() => request as unknown as XMLHttpRequest
			)
		).not.toBeNull();
		expect( request.timeout ).toBe( SEND_TIMEOUT_MS );

		expect( request.opened ).toEqual( [
			'POST',
			'https://site.test/wp-admin/admin-post.php',
		] );
		expect( request.body?.get( 'action' ) ).toBe( 'aggr_upload_creative' );
		expect( request.body?.get( '_wpnonce' ) ).toBe( 'abc' );
		expect( request.body?.get( 'click_url' ) ).toBe(
			'https://example.com/'
		);
	} );

	it( 'reports each whole percent once, not every packet', () => {
		const request = new FakeRequest();
		const on = handlers();

		sendWithProgress(
			form(),
			on,
			() => request as unknown as XMLHttpRequest
		);

		request.progress( 10, 1000 );
		request.progress( 11, 1000 );
		request.progress( 12, 1000 );
		request.progress( 640, 1000 );
		request.progress( 1000, 1000 );

		expect( on.progress.mock.calls.map( ( call ) => call[ 0 ] ) ).toEqual( [
			1, 64, 100,
		] );
	} );

	it( 'goes where the server sent it, which is where its message is', () => {
		const request = new FakeRequest();
		const on = handlers();

		sendWithProgress(
			form(),
			on,
			() => request as unknown as XMLHttpRequest
		);
		request.responseURL =
			'https://site.test/advertiser/campaigns/7/?step=creative&aggr_notice=uploaded';
		request.emit( 'load' );

		expect( on.done ).toHaveBeenCalledWith(
			'https://site.test/advertiser/campaigns/7/?step=creative&aggr_notice=uploaded'
		);
		expect( on.fail ).not.toHaveBeenCalled();
	} );

	it.each( [
		[ 'error', 'error' ],
		[ 'abort', 'cancelled' ],
		[ 'timeout', 'timeout' ],
	] )( 'hands a %s back as %s, rather than retrying', ( type, reason ) => {
		const request = new FakeRequest();
		const on = handlers();
		let made = 0;

		sendWithProgress( form(), on, () => {
			made++;
			return request as unknown as XMLHttpRequest;
		} );
		request.emit( type );

		expect( on.fail ).toHaveBeenCalledWith( reason );
		expect( on.done ).not.toHaveBeenCalled();
		// One request, ever: a retry could leave two creatives.
		expect( made ).toBe( 1 );
	} );

	it( 'cancels on request, and ends exactly once', () => {
		const request = new FakeRequest();
		const on = handlers();
		const handle = sendWithProgress(
			form(),
			on,
			() => request as unknown as XMLHttpRequest
		);

		handle?.cancel();
		// A late answer after a cancel must not navigate anywhere.
		request.emit( 'load' );
		request.emit( 'error' );

		expect( request.aborted ).toBe( 1 );
		expect( on.fail ).toHaveBeenCalledTimes( 1 );
		expect( on.fail ).toHaveBeenCalledWith( 'cancelled' );
		expect( on.done ).not.toHaveBeenCalled();
	} );

	it( 'declines, sending nothing, where the browser cannot report progress', () => {
		const on = handlers();

		expect(
			sendWithProgress( form(), on, () => {
				throw new Error( 'no XMLHttpRequest' );
			} )
		).toBeNull();

		const noUpload = { upload: null } as unknown as XMLHttpRequest;

		expect( sendWithProgress( form(), on, () => noUpload ) ).toBeNull();
		expect( on.progress ).not.toHaveBeenCalled();
		expect( on.done ).not.toHaveBeenCalled();
	} );
} );
