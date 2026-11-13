/**
 * Local-only research for the P18 isolation contract.
 *
 * Run: node bin/dev/probe-p18-isolation.mjs (requires installed Chromium/WebKit).
 * Exit 1 reports a failed control or observed local STUN escape. Exit 0 covers
 * only this fixture, not full browser qualification or production authorization.
 * No WordPress data, remote service, or non-loopback socket is used.
 */
import http from 'node:http';
import dgram from 'node:dgram';
import { createHash } from 'node:crypto';
import { chromium, webkit } from '@playwright/test';

const udp = dgram.createSocket( 'udp4' );
let rtcPackets = 0;
udp.on( 'message', () => {
	rtcPackets++;
} );
await new Promise( ( r ) => udp.bind( 0, '127.0.0.1', r ) );
const rtcPort = udp.address().port;
const digest = ( value ) =>
	`'sha256-${ createHash( 'sha256' ).update( value ).digest( 'base64' ) }'`;
let port;
const hits = [];
const server = http.createServer( ( req, res ) => {
	hits.push( req.url );
	if ( req.url !== '/' ) {
		res.end( 'unexpected network' );
		return;
	}
	const creative = `document.body.dataset.ran='yes';try{const pc=new RTCPeerConnection({iceServers:[{urls:'stun:127.0.0.1:${ rtcPort }'}]});pc.createDataChannel('probe');pc.createOffer().then(o=>pc.setLocalDescription(o));document.body.dataset.rtc='started'}catch{document.body.dataset.rtc='refused'};try{parent.document.body.dataset.leaked='yes'}catch{document.body.dataset.isolated='yes'};fetch('http://127.0.0.1:${ port }/fetch').catch(()=>{});const i=new Image();i.src='http://127.0.0.1:${ port }/image';setTimeout(()=>location.href='http://127.0.0.1:${ port }/escape',700);`;
	const childPolicy = `webrtc 'block'; default-src 'none'; script-src ${ digest(
		creative
	) }; img-src data:; style-src 'unsafe-inline'; connect-src 'none'; frame-src 'none'; base-uri 'none'; form-action 'none'; object-src 'none'`;
	const html = `<meta http-equiv="Content-Security-Policy" content="${ childPolicy }"><body><p>animation fixture</p><script>${ creative }</script></body>`;
	const encoded = JSON.stringify( html ).replaceAll( '<', '\\u003c' );
	const bootstrap = `const f=document.createElement('iframe');f.sandbox='allow-scripts';f.src=URL.createObjectURL(new Blob([${ encoded }],{type:'text/html'}));document.body.append(f);`;
	const policy = `webrtc 'block'; default-src 'none'; script-src ${ digest(
		bootstrap
	) } ${ digest(
		creative
	) }; frame-src blob:; img-src data:; style-src 'unsafe-inline'; connect-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'`;
	res.writeHead( 200, {
		'Content-Type': 'text/html; charset=utf-8',
		'Content-Security-Policy': policy,
		'X-Content-Type-Options': 'nosniff',
		'Referrer-Policy': 'no-referrer',
		'Cache-Control': 'no-store',
	} );
	res.end( `<body><script>${ bootstrap }</script></body>` );
} );
await new Promise( ( r ) => server.listen( 0, '127.0.0.1', r ) );
port = server.address().port;
try {
	for ( const [ name, engine ] of [
		[ 'chromium', chromium ],
		[ 'webkit', webkit ],
	] ) {
		const browser = await engine.launch();
		try {
			hits.length = 0;
			rtcPackets = 0;
			const page = await browser.newPage();
			const errors = [];
			page.on( 'console', ( message ) => {
				if ( message.type() === 'error' ) errors.push( message.text() );
			} );
			await page.goto( `http://127.0.0.1:${ port }/` );
			await page.locator( 'iframe' ).waitFor();
			await page.waitForTimeout( 300 );
			const child = page.frames().find( ( f ) => f !== page.mainFrame() );
			const state = child
				? await child
						.locator( 'body' )
						.evaluate( ( el ) => ( { ...el.dataset } ) )
				: {};
			const parentLeak = await page
				.locator( 'body' )
				.getAttribute( 'data-leaked' );
			await page.waitForTimeout( 700 );
			const unexpected = hits.filter( ( x ) => x !== '/' );
			const ok =
				state.ran === 'yes' &&
				state.isolated === 'yes' &&
				parentLeak === null &&
				unexpected.length === 0;
			console.log(
				JSON.stringify( {
					name,
					version: browser.version(),
					domAndHttpControlsPassed: ok,
					sampledNetworkChannelsBlocked: rtcPackets === 0,
					state,
					parentLeak,
					unexpected,
					rtcPackets,
					blockedPolicies: errors.length,
					errors: errors
						.filter( ( s ) => s.includes( 'webrtc' ) )
						.slice( 0, 2 ),
				} )
			);
			if ( ! ok || rtcPackets > 0 ) process.exitCode = 1;
		} finally {
			await browser.close();
		}
	}
} finally {
	await new Promise( ( r ) => server.close( r ) );
	udp.close();
}
