<?php

namespace fpoirotte\Cryptal\Tests\API\Modes;

use fpoirotte\Cryptal\Registry;
use fpoirotte\Cryptal\CipherEnum;
use fpoirotte\Cryptal\ModeEnum;
use fpoirotte\Cryptal\ImplementationTypeEnum;
use fpoirotte\Cryptal\Tests\AesBasedTestCase;

class EAXTest extends AesBasedTestCase
{
    public function setUp()
    {
        $registry = Registry::getInstance();
        $registry->addCipher(
            '',
            '\\fpoirotte\\Cryptal\\Tests\\AesEcbStub',
            CipherEnum::CIPHER_AES_128(),
            ModeEnum::MODE_ECB(),
            ImplementationTypeEnum::TYPE_USERLAND()
        );
    }

    public function vectors()
    {
        // P, K, N, A, C, T
        return array(
            // Test vectors from https://cseweb.ucsd.edu/~mihir/papers/eax.html
            array(
                '',
                '233952DEE4D5ED5F9B9C6D6FF80FF478',
                '62EC67F9C3A4A407FCB2A8C49031A8B3',
                '6BFB914FD07EAE6B',
                '',
                'E037830E8389F27B025A2D6527E79D01',
            ),
            array(
                'F7FB',
                '91945D3F4DCBEE0BF45EF52255F095A4',
                'BECAF043B0A23D843194BA972C66DEBD',
                'FA3BFD4806EB53FA',
                '19DD',
                '5C4C9331049D0BDAB0277408F67967E5',
            ),
            array(
                '1A47CB4933',
                '01F74AD64077F2E704C0F60ADA3DD523',
                '70C3DB4F0D26368400A10ED05D2BFF5E',
                '234A3463C1264AC6',
                'D851D5BAE0',
                '3A59F238A23E39199DC9266626C40F80',
            ),
            array(
                '481C9E39B1',
                'D07CF6CBB7F313BDDE66B727AFD3C5E8',
                '8408DFFF3C1A2B1292DC199E46B7D617',
                '33CCE2EABFF5A79D',
                '632A9D131A',
                'D4C168A4225D8E1FF755939974A7BEDE',
            ),
            array(
                '40D0C07DA5E4',
                '35B6D0580005BBC12B0587124557D2C2',
                'FDB6B06676EEDC5C61D74276E1F8E816',
                'AEB96EAEBE2970E9',
                '071DFE16C675',
                'CB0677E536F73AFE6A14B74EE49844DD',
            ),
            array(
                '4DE3B35C3FC039245BD1FB7D',
                'BD8E6E11475E60B268784C38C62FEB22',
                '6EAC5C93072D8E8513F750935E46DA1B',
                'D4482D1CA78DCE0F',
                '835BB4F15D743E350E728414',
                'ABB8644FD6CCB86947C5E10590210A4F',
            ),
            array(
                '8B0A79306C9CE7ED99DAE4F87F8DD61636',
                '7C77D6E813BED5AC98BAA417477A2E7D',
                '1A8C98DCD73D38393B2BF1569DEEFC19',
                '65D2017990D62528',
                '02083E3979DA014812F59F11D52630DA30',
                '137327D10649B0AA6E1C181DB617D7F2',
            ),
            array(
                '1BDA122BCE8A8DBAF1877D962B8592DD2D56',
                '5FFF20CAFAB119CA2FC73549E20F5B0D',
                'DDE59B97D722156D4D9AFF2BC7559826',
                '54B9F04E6A09189A',
                '2EC47B2C4954A489AFC7BA4897EDCDAE8CC3',
                '3B60450599BD02C96382902AEF7F832A',
            ),
            array(
                '6CF36720872B8513F6EAB1A8A44438D5EF11',
                'A4A4782BCFFD3EC5E7EF6D8C34A56123',
                'B781FCF2F75FA5A8DE97A9CA48E522EC',
                '899A175897561D7E',
                '0DE18FD0FDD91E7AF19F1D8EE8733938B1E8',
                'E7F6D2231618102FDB7FE55FF1991700',
            ),
            array(
                'CA40D7446E545FFAED3BD12A740A659FFBBB3CEAB7',
                '8395FCF1E95BEBD697BD010BC766AAC3',
                '22E7ADD93CFC6393C57EC0B3C17D6B44',
                '126735FCC320D25A',
                'CB8920F87A6C75CFF39627B56E3ED197C552D295A7',
                'CFC46AFC253B4652B1AF3795B124AB6E',
            ),
        );
    }

    /**
     * @dataProvider vectors
     */
    public function testEAX_Mode($P, $K, $N, $A, $C, $T)
    {
        $K  = pack('H*', $K);
        $P  = pack('H*', $P);
        $A  = pack('H*', $A);
        $N  = pack('H*', $N);
        $C  = strtolower($C);
        $T  = strtolower($T);

        $cipher     = $this->getCipher($K);
        $eax        = new \fpoirotte\Cryptal\Modes\EAX($cipher, $N, strlen($T) >> 1);
        $ctx        = stream_context_create(array('cryptal' => array('data'  => $A)));

        $res        = $eax->encrypt($P, $ctx);
        $options    = stream_context_get_options($ctx);
        $this->assertSame($C, bin2hex($res));
        $this->assertSame($T, bin2hex($options['cryptal']['tag']));

        $res        = $eax->decrypt($res, $ctx);
        $this->assertSame(bin2hex($P), bin2hex($res));
    }
}
