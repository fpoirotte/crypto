<?php

namespace fpoirotte\Cryptal\DefaultAlgorithms;

use fpoirotte\Cryptal\Padding\None;
use fpoirotte\Cryptal\Implementers\CryptoInterface;
use fpoirotte\Cryptal\Implementers\MacInterface;
use fpoirotte\Cryptal\SubAlgorithmAbstractEnum;
use fpoirotte\Cryptal\CipherEnum;
use fpoirotte\Cryptal\ModeEnum;
use fpoirotte\Cryptal\MacEnum;
use fpoirotte\Cryptal\Registry;

/**
 * Message authentication code based on universal hashing.
 *
 */
class Umac extends MacInterface
{
    /// 36-bits prime number, in hexadecimal notation.
    const PRIME_36  = '0x0000000FFFFFFFFB';

    /// 64-bits prime number, in hexadecimal notation.
    const PRIME_64  = '0xFFFFFFFFFFFFFFC5';

    /// 128-bits prime number, in hexadecimal notation.
    const PRIME_128 = '0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF61';

    /// Cipher algorithm used to encrypt data.
    protected $cipher;

    /// Block size for the cipher.
    protected $blkSize;

    /// Length of tags generated by this instance.
    protected $taglen;

    /// Secret key
    private $key;

    /// Nonce
    private $nonce;

    /// = 2**32
    static protected $twop32;

    /// = 2**64
    static protected $twop64;

    public function __construct(MacEnum $macAlgorithm, SubAlgorithmAbstractEnum $innerAlgorithm, $key, $nonce = '')
    {
        $supported = array(
            4   => MacEnum::MAC_UMAC_32(),
            8   => MacEnum::MAC_UMAC_64(),
            12  => MacEnum::MAC_UMAC_96(),
            16  => MacEnum::MAC_UMAC_128(),
        );

        $taglen = array_search($macAlgorithm, $supported);
        if (false === $taglen || !extension_loaded('gmp')) {
            throw new \InvalidArgumentException('Unsupported MAC algorithm');
        }

        if (!($innerAlgorithm instanceof CipherEnum)) {
            throw new \InvalidArgumentException('A cipher was expected as the inner algorithm');
        }

        if (!is_string($nonce)) {
            throw new \InvalidArgumentException('Invalid key');
        }

        if (!is_string($nonce)) {
            throw new \InvalidArgumentException('Invalid nonce');
        }

        $cipher     = Registry::buildCipher($innerAlgorithm, ModeEnum::MODE_ECB(), new None, $key, 0, true);
        $blkSize    = $cipher->getBlockSize();

        if (16 > $blkSize || '1' !== trim(base_convert($blkSize, 10, 2), '0')) {
            throw new \InvalidArgumentException('Incompatible cipher');
        }

        $this->cipher   = $cipher;
        $this->subAlgo  = $innerAlgorithm;
        $this->blkSize  = $blkSize;
        $this->taglen   = $taglen;
        $this->key      = $key;
        $this->nonce    = $nonce;
        $this->data     = '';

        self::$twop32   = gmp_pow(2, 32);
        self::$twop64   = gmp_pow(2, 64);
    }

    protected function internalUpdate($data)
    {
        $this->data .= $data;
    }

    protected function internalFinish()
    {
        $hashed = $this->UHASH($this->data);
        $pad = $this->PDF($this->nonce);
        $tag = gmp_xor(gmp_init(bin2hex($pad), 16), gmp_init(bin2hex($hashed), 16));
        $tag = gmp_strval($tag, 16);
        $tag = pack('H*', str_pad($tag, $this->taglen << 1, '0', STR_PAD_LEFT));
        return $tag;
    }

    protected function KDF($index, $numbytes)
    {
        // Calculate number of block cipher iterations
        $n = (int) ceil($numbytes / $this->blkSize);
        $y = '';
        $bhex = (($this->blkSize - 8) << 1);
        $pad = "%0${bhex}X%016X";

        // Build Y using the block cipher in counter mode
        for ($i = 1; $i <= $n; $i++) {
            $t = pack('H*', sprintf($pad, $index, $i));
            $t = $this->cipher->encrypt('', $t);
            $y .= $t;
        }
        return substr($y, 0, $numbytes);
    }

    protected function PDF($nonce)
    {
        $nlen   = strlen($nonce);
        $nonce  = gmp_init(bin2hex($nonce), 16);

        // Extract and zero low bit(s) of Nonce if needed
        if ($this->taglen <= 8) {
            $index = gmp_intval(gmp_mod($nonce, gmp_init($this->blkSize / $this->taglen)));
            $nonce = gmp_xor($nonce, $index);
        }

        $nonce = gmp_strval($nonce, 16);
        $nonce = pack('H*', str_pad($nonce, $nlen << 1, '0', STR_PAD_LEFT));

        // Make Nonce BLOCKLEN bytes by appending zeroes if needed
        $nonce = str_pad($nonce, $this->blkSize, "\x00");

        $kprime = $this->KDF(0, strlen($this->key));
        $cipher = Registry::buildCipher($this->subAlgo, ModeEnum::MODE_ECB(), new None, $kprime, 0, true);
        $t = $cipher->encrypt('', $nonce);

        if ($this->taglen <= 8) {
            return substr($t, $index * $this->taglen, $this->taglen);
        } else {
            return substr($t, 0, $this->taglen);
        }
    }

    protected function UHASH($m)
    {
        $iters  = $this->taglen >> 2;
        $l1key  = $this->KDF(1, 1024 + ($iters - 1) << 4);
        $l2key  = $this->KDF(2, $iters * 24);
        $l3key1 = $this->KDF(3, $iters * 64);
        $l3key2 = $this->KDF(4, $iters * 4);

        $y = '';
        for ($i = 0; $i < $iters; $i++) {
            $l1key_i    = substr($l1key, $i << 4, 1024);
            $l2key_i    = substr($l2key, $i * 24, 24);
            $l3key1_i   = substr($l3key1, $i << 6, 64);
            $l3key2_i   = substr($l3key2, $i << 2, 4);

            $a = $this->l1Hash($l1key_i, $m);
            if (strlen($m) <= 1024) {
                $b = "\x00\x00\x00\x00\x00\x00\x00\x00" . $a;
            } else {
                $b = $this->l2Hash($l2key_i, $a);
            }
            $c = $this->l3Hash($l3key1_i, $l3key2_i, $b);
            $y .= $c;
        }
        return $y;
    }

    protected function l1Hash($k, $m)
    {
        // Break M into 1024 byte chunks (final chunk may be shorter)
        $ms     = str_split($m, 1024);

        // For each chunk, except the last: endian-adjust, NH hash
        // and add bit-length.  Use results to build Y.
        $len    = gmp_init("0x2000", 16);
        $y      = '';
        $last   = array_pop($ms);
        $k_i    = str_split(substr($k, 0, 1024), 4);
        foreach ($ms as $mp) {
            $v = unpack('V*', $mp);
            array_unshift($v, 'N*');
            $m_i = call_user_func_array('pack', $v);
            $nh = gmp_strval($this->NH($k_i, $m_i, $len), 16);
            $y .= pack('H*', str_pad($nh, 16, '0', STR_PAD_LEFT));
        }

        // For the last chunk: pad to 32-byte boundary, endian-adjust,
        // NH hash and add bit-length.  Concatenate the result to Y.
        $len    = gmp_init(strlen($last) * 8);
        $last   = str_pad($last, max(32, ((strlen($last) + 31) >> 5) << 5), "\x00");
        $v      = unpack('V*', $last);
        array_unshift($v, 'N*');
        $m_t    = call_user_func_array('pack', $v);
        $k_i    = str_split(substr($k, 0, strlen($m_t)), 4);
        $nh     = gmp_strval($this->NH($k_i, $m_t, $len), 16);
        $y     .= pack('H*', str_pad($nh, 16, '0', STR_PAD_LEFT));
        return $y;
    }

    protected function NH($k_i, $m, $len)
    {
        // Break M and K into 4-byte chunks
        $m_i = str_split($m, 4);

        // Perform NH hash on the chunks, pairing words for multiplication
        // which are 4 apart to accommodate vector-parallelism.
        $y = gmp_init(0);
        for ($i = 0, $t = count($m_i) >> 3; $i < $t; $i++) {
            for ($j = 0; $j < 4; $j++) {
                $y = gmp_add(
                    $y,
                    gmp_mul(
                        gmp_mod(
                            gmp_add(
                                gmp_init(bin2hex($m_i[8 * $i + $j]), 16),
                                gmp_init(bin2hex($k_i[8 * $i + $j]), 16)
                            ),
                            self::$twop32
                        ),
                        gmp_mod(
                            gmp_add(
                                gmp_init(bin2hex($m_i[8 * $i + $j + 4]), 16),
                                gmp_init(bin2hex($k_i[8 * $i + $j + 4]), 16)
                            ),
                            self::$twop32
                        )
                    )
                );
            }
        }
        $y = gmp_mod(gmp_add($y, $len), self::$twop64);
        return $y;
    }

    protected function l2Hash($k, $m)
    {
        //  Extract keys and restrict to special key-sets
        $mask64     = gmp_init('0x01ffffff01ffffff', 16);
        $mask128    = gmp_init('0x01ffffff01ffffff01ffffff01ffffff', 16);
        $k64        = gmp_and(gmp_init(bin2hex(substr($k, 0, 8)), 16), $mask64);
        $k128       = gmp_and(gmp_init(bin2hex(substr($k, 8, 16)), 16), $mask128);

        // If M is no more than 2^17 bytes, hash under 64-bit prime,
        // otherwise, hash first 2^17 bytes under 64-bit prime and
        // remainder under 128-bit prime.
        if (strlen($m) <= (1 << 17)) {
            // View M as an array of 64-bit words, and use POLY modulo
            // prime(64) (and with bound 2^64 - 2^32) to hash it.
            $y = $this->POLY(
                64,
                gmp_sub(
                    gmp_init('0x10000000000000000', 16),
                    gmp_init('0x100000000', 16)
                ),
                $k64,
                $m
            );
        } else {
            $m_1 = substr($m, 0, 1 << 17);
            $m_2 = substr($m, 1 << 17) . "\x80";
            $m_2 = str_pad($m_2, max(16, ((strlen($m_2) + 15) >> 4) << 4), "\x00");

            $y = $this->POLY(
                64,
                gmp_sub(gmp_pow(2, 64), gmp_pow(2, 32)),
                $k64,
                $m_1
            );
            $y = $this->POLY(
                128,
                gmp_sub(gmp_pow(2, 128), gmp_pow(2, 96)),
                $k128,
                pack('H*', substr(str_repeat('00', 16) . gmp_strval($y, 16), -32)) . $m_2
            );
        }

        $res = substr(str_repeat('00', 16) . gmp_strval($y, 16), -32);
        return pack('H*', $res);
    }

    protected function POLY($wordbits, $maxwordrange, $k, $m)
    {
        $wordbytes  = $wordbits >> 3;
        $p          = gmp_init(constant(__CLASS__ . '::PRIME_' . $wordbits), 16);
        $offset     = gmp_sub(gmp_pow(2, $wordbits), $p);
        $marker     = gmp_sub($p, 1);

        // Break M into chunks of length wordbytes bytes
        $m_i        = str_split($m, $wordbytes);

        // Each input word m is compared with maxwordrange.  If not smaller
        // then 'marker' and (m - offset), both in range, are hashed.
        $y = gmp_init(1);
        for ($i = 0, $n = count($m_i); $i < $n; $i++) {
            $m = gmp_init(bin2hex($m_i[$i]), 16);
            if (gmp_cmp($m, $maxwordrange) >= 0) {
                $y = gmp_mod(gmp_add(gmp_mul($k, $y), $marker), $p);
                $y = gmp_mod(gmp_add(gmp_mul($k, $y), gmp_sub($m, $offset)), $p);
            } else {
                $y = gmp_mod(gmp_add(gmp_mul($k, $y), $m), $p);
            }
        }

        return $y;
    }

    protected function l3Hash($k1, $k2, $m)
    {
        $y = gmp_init(0);
        $prime36 = gmp_init(self::PRIME_36, 16);
        for ($i = 0; $i < 8; $i++) {
            $m_i = gmp_init(bin2hex(substr($m, $i << 1, 2)), 16);
            $k_i = gmp_mod(gmp_init(bin2hex(substr($k1, $i << 3, 8)), 16), $prime36);
            $y = gmp_add($y, gmp_mul($m_i, $k_i));
        }
        $y = gmp_and(gmp_mod($y, $prime36), '0xFFFFFFFF');
        $y = gmp_xor($y, gmp_init(bin2hex($k2), 16));
        $y = pack('H*', str_pad(gmp_strval($y, 16), 8, '0', STR_PAD_LEFT));
        return $y;
    }
}