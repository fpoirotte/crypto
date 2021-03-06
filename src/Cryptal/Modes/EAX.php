<?php

namespace fpoirotte\Cryptal\Modes;

use fpoirotte\Cryptal\Implementers\CryptoInterface;
use fpoirotte\Cryptal\AsymmetricModeInterface;
use fpoirotte\Cryptal\DefaultAlgorithms\Cmac;
use fpoirotte\Cryptal\MacEnum;

/**
 * Cipher Block Chaining mode
 */
class EAX implements AsymmetricModeInterface
{
    /// Cipher
    protected $cipher;

    /// Nonce
    protected $nonce;

    /// Output tag length
    protected $taglen;

    public function __construct(CryptoInterface $cipher, $iv, $tagLength)
    {
        $this->cipher   = $cipher;
        $this->nonce    = $iv;
        $this->taglen   = $tagLength;
        $this->omac     = new Cmac(MacEnum::MAC_CMAC(), $cipher->getCipher(), $cipher->getKey());
    }

    public function encrypt($data, $context)
    {
        $options    = stream_context_get_options($context);
        $H          = isset($options['cryptal']['data']) ? (string) $options['cryptal']['data'] : '';
        $blockSize  = $this->cipher->getBlockSize();
        $pad        = str_repeat("\x00", $blockSize - 1);

        $omac       = clone $this->omac;
        $tN         = $omac->update($pad . "\x00" . $this->nonce)->finalize(true);
        $omac       = clone $this->omac;
        $tH         = $omac->update($pad . "\x01" . $H)->finalize(true);

        $ctr    = new CTR($this->cipher, $tN, $this->taglen);
        $C      = '';
        foreach (str_split($data, $blockSize) as $block) {
            $C .= $ctr->encrypt($block, null);
        }

        $omac       = clone $this->omac;
        $tC         = $omac->update($pad . "\x02" . $C)->finalize(true);
        stream_context_set_option($context, 'cryptal', 'tag', (string) substr($tN ^ $tH ^ $tC, 0, $this->taglen));
        return $C;
    }

    public function decrypt($data, $context)
    {
        $options    = stream_context_get_options($context);
        $H          = isset($options['cryptal']['data']) ? (string) $options['cryptal']['data'] : '';
        $T          = isset($options['cryptal']['tag']) ? (string) $options['cryptal']['tag'] : '';
        $blockSize  = $this->cipher->getBlockSize();
        $pad        = str_repeat("\x00", $blockSize - 1);

        $omac       = clone $this->omac;
        $tN         = $omac->update($pad . "\x00" . $this->nonce)->finalize(true);
        $omac       = clone $this->omac;
        $tH         = $omac->update($pad . "\x01" . $H)->finalize(true);
        $omac       = clone $this->omac;
        $tC         = $omac->update($pad . "\x02" . $data)->finalize(true);
        $T2         = (string) substr($tN ^ $tH ^ $tC, 0, $this->taglen);

        if ($T2 !== $T) {
            throw new \InvalidArgumentException('Tag does not match expected value');
        }

        $ctr    = new CTR($this->cipher, $tN, $this->taglen);
        $P      = '';
        foreach (str_split($data, $blockSize) as $block) {
            $P .= $ctr->encrypt($block, null);
        }

        return $P;
    }
}
