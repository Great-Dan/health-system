<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| BASE32 DECODER
|--------------------------------------------------------------------------
*/

function base32_decode_custom(
    string $input
): string {

    $alphabet =
        'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';


    $input = strtoupper(

        preg_replace(

            '/[^A-Z2-7]/',

            '',

            $input

        ) ?? ''

    );


    $buffer = 0;

    $bitsLeft = 0;

    $output = '';


    for (

        $i = 0,
        $len = strlen($input);

        $i < $len;

        $i++

    ) {

        $value =
            strpos(

                $alphabet,

                $input[$i]

            );


        if ($value === false) {

            continue;

        }


        $buffer =
            ($buffer << 5)
            |
            $value;


        $bitsLeft += 5;


        if ($bitsLeft >= 8) {

            $bitsLeft -= 8;


            $output .= chr(

                ($buffer >> $bitsLeft)
                &
                0xFF

            );

        }

    }


    return $output;
}


/*
|--------------------------------------------------------------------------
| BASE32 ENCODER
|--------------------------------------------------------------------------
*/

function base32_encode_custom(
    string $data
): string {

    $alphabet =
        'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';


    $buffer = 0;

    $bitsLeft = 0;

    $output = '';


    for (

        $i = 0,
        $len = strlen($data);

        $i < $len;

        $i++

    ) {

        $buffer =
            ($buffer << 8)
            |
            ord($data[$i]);


        $bitsLeft += 8;


        while ($bitsLeft >= 5) {

            $bitsLeft -= 5;


            $output .=
                $alphabet[
                    ($buffer >> $bitsLeft)
                    &
                    31
                ];

        }

    }


    if ($bitsLeft > 0) {

        $output .=

            $alphabet[
                ($buffer << (5 - $bitsLeft))
                &
                31
            ];

    }


    return $output;
}


/*
|--------------------------------------------------------------------------
| GENERATE SECRET
|--------------------------------------------------------------------------
*/

function generate_totp_secret(): string
{
    return base32_encode_custom(

        random_bytes(20)

    );
}


/*
|--------------------------------------------------------------------------
| GENERATE TOTP CODE
|--------------------------------------------------------------------------
*/

function totp_code(

    string $secret,

    ?int $timestamp = null

): string {

    $timestamp =
        $timestamp ?? time();


    $counter =
        intdiv(

            $timestamp,

            30

        );


    $binaryCounter =

        pack('N*', 0)
        .
        pack('N*', $counter);


    $key =
        base32_decode_custom($secret);


    $hash = hash_hmac(

        'sha1',

        $binaryCounter,

        $key,

        true

    );


    $offset =
        ord($hash[19]) & 0x0f;


    $binary =

        (
            (ord($hash[$offset]) & 0x7f)
            << 24
        )

        |

        (
            (ord($hash[$offset + 1]) & 0xff)
            << 16
        )

        |

        (
            (ord($hash[$offset + 2]) & 0xff)
            << 8
        )

        |

        (
            ord($hash[$offset + 3])
            & 0xff
        );


    return str_pad(

        (string)($binary % 1000000),

        6,

        '0',

        STR_PAD_LEFT

    );
}


/*
|--------------------------------------------------------------------------
| VERIFY TOTP
|--------------------------------------------------------------------------
*/

function verify_totp(

    string $secret,

    string $code

): bool {

    $code =
        preg_replace(

            '/\D/',

            '',

            $code

        ) ?? '';


    if (strlen($code) !== 6) {

        return false;

    }


    /*
    |--------------------------------------------------------------------------
    | Allow 30-second clock drift
    |--------------------------------------------------------------------------
    */

    foreach ([-1, 0, 1] as $window) {

        if (

            hash_equals(

                totp_code(

                    $secret,

                    time()
                    +
                    ($window * 30)

                ),

                $code

            )

        ) {

            return true;

        }

    }


    return false;
}


/*
|--------------------------------------------------------------------------
| AUTHENTICATOR URI
|--------------------------------------------------------------------------
*/

function totp_uri(

    string $issuer,

    string $account,

    string $secret

): string {

    $label = rawurlencode(

        $issuer . ':' . $account

    );


    return

        'otpauth://totp/'
        .
        $label

        .

        '?secret='
        .
        rawurlencode($secret)

        .

        '&issuer='
        .
        rawurlencode($issuer)

        .

        '&algorithm=SHA1'

        .

        '&digits=6'

        .

        '&period=30';
}