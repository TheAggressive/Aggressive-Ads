import { deflateSync } from 'node:zlib';

const crcTable = Array.from( { length: 256 }, ( _, index ) => {
	let value = index;

	for ( let bit = 0; bit < 8; bit += 1 ) {
		value = ( value & 1 ) === 1 ? 0xedb88320 ^ ( value >>> 1 ) : value >>> 1;
	}

	return value >>> 0;
} );

function crc32( value: Buffer ): number {
	let crc = 0xffffffff;

	for ( const byte of value ) {
		crc = ( crc >>> 8 ) ^ crcTable[ ( crc ^ byte ) & 0xff ];
	}

	return ( crc ^ 0xffffffff ) >>> 0;
}

function chunk( type: string, data: Buffer ): Buffer {
	const name = Buffer.from( type, 'ascii' );
	const body = Buffer.concat( [ name, data ] );
	const output = Buffer.alloc( data.length + 12 );

	output.writeUInt32BE( data.length, 0 );
	body.copy( output, 4 );
	output.writeUInt32BE( crc32( body ), data.length + 8 );

	return output;
}

export function solidPng( width: number, height: number ): Buffer {
	const header = Buffer.alloc( 13 );
	header.writeUInt32BE( width, 0 );
	header.writeUInt32BE( height, 4 );
	header[ 8 ] = 8;
	header[ 9 ] = 2;

	const stride = 1 + width * 3;
	const pixels = Buffer.alloc( stride * height );

	for ( let y = 0; y < height; y += 1 ) {
		const row = y * stride;
		pixels[ row ] = 0;

		for ( let x = 0; x < width; x += 1 ) {
			const pixel = row + 1 + x * 3;
			pixels[ pixel ] = 31;
			pixels[ pixel + 1 ] = 79;
			pixels[ pixel + 2 ] = 121;
		}
	}

	return Buffer.concat( [
		Buffer.from( '89504e470d0a1a0a', 'hex' ),
		chunk( 'IHDR', header ),
		chunk( 'IDAT', deflateSync( pixels ) ),
		chunk( 'IEND', Buffer.alloc( 0 ) ),
	] );
}
