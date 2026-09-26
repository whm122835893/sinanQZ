<?php
declare(strict_types=1);

namespace tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 纯函数单元测试：不碰 HTTP、不碰数据库，可离线秒跑
 *
 * 被测对象是 app/common.php 里的真实实现（bootstrap 直接 require，不再手抄一份），
 * 这些函数遍布脱敏展示、加密落库、昵称合规过滤等入口，出错会静默污染数据。
 */
class HelperFunctionsTest extends TestCase
{
    // ============================================================
    // 编号 / 展示
    // ============================================================

    public function testGenUidPadsIdToSixDigits(): void
    {
        $this->assertSame('U000001', gen_uid(1));
        $this->assertSame('U123456', gen_uid(123456));
        // 超过 6 位不截断（str_pad 对更长字符串是原样返回）
        $this->assertSame('U1234567', gen_uid(1234567));
    }

    /** 邀请码字符集剔除了易混字符（0/O、1/I/L），客服读号不会认错 */
    public function testGenInviteCodeAvoidsConfusableCharacters(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $code = gen_invite_code();
            $this->assertSame(8, strlen($code));
            $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $code);
        }
        $this->assertSame(12, strlen(gen_invite_code(12)));
    }

    public function testGenOrderNoFitsVarchar20Column(): void
    {
        $no = gen_order_no();
        $this->assertSame(20, strlen($no), 'order_no 列为 VARCHAR(20)，超长会被静默截断');
        $this->assertMatchesRegularExpression('/^JC' . date('ymdHis') . '\d{6}$/', $no);
    }

    public function testGenDrawCodeAndSerialPlaceholderFormats(): void
    {
        $this->assertMatchesRegularExpression('/^S' . date('ymd') . '\d{6}$/', gen_draw_code());

        $serial = gen_serial_placeholder();
        $this->assertMatchesRegularExpression('/^TMP-[0-9a-f]{16}$/', $serial);
        $this->assertLessThanOrEqual(20, strlen($serial));
    }

    public function testGenDefaultNicknameUsesLastFourPhoneDigits(): void
    {
        $this->assertSame('司南-1234', gen_default_nickname('13900001234'));
        // 异常手机号（长度不足）时回落到随机 4 位，不能生成空后缀
        $this->assertMatchesRegularExpression('/^司南-\d{4}$/', gen_default_nickname(''));
    }

    // ============================================================
    // 脱敏
    // ============================================================

    public function testMaskPhoneKeepsPrefixAndSuffixOnlyForElevenDigits(): void
    {
        $this->assertSame('138****8000', mask_phone('13800138000'));
        // 非 11 位（海外号 / 脏数据）原样返回，避免把号码截成另一个号码
        $this->assertSame('1380013800', mask_phone('1380013800'));
        $this->assertSame('', mask_phone(''));
    }

    #[DataProvider('nameMaskProvider')]
    public function testMaskNamePreservesFirstAndLastCharacter(string $name, string $expected): void
    {
        $this->assertSame($expected, mask_name($name));
    }

    public static function nameMaskProvider(): array
    {
        return [
            '单字不脱敏'         => ['张', '张'],
            '两字保留首字'       => ['张三', '张*'],
            '三字中间打码'       => ['张三丰', '张*丰'],
            '多字按字符数打码'   => ['欧阳娜娜娜', '欧***娜'],
            '按字符而非字节'     => ['阿·蛮', '阿*蛮'],
        ];
    }

    // ============================================================
    // 字段命名转换
    // ============================================================

    public function testCamelizeConvertsSnakeCaseKeysRecursively(): void
    {
        $this->assertSame('saleEndTime', camelize('sale_end_time'));
        $this->assertSame('id', camelize('id'));

        $converted = camelize_keys([
            'user_id'    => 7,
            'order_info' => ['item_no' => 'JC001', 0 => 'indexed'],
            'plain'      => 'value',
        ]);
        $this->assertSame([
            'userId'     => 7,
            'orderInfo'  => ['itemNo' => 'JC001', 0 => 'indexed'],
            'plain'      => 'value',
        ], $converted, '数字键必须保持原样，否则前端按下标访问会错位');
    }

    // ============================================================
    // 口令与实名密文
    // ============================================================

    public function testPasswordHashIsBcryptSaltedAndVerifiable(): void
    {
        $hash = hash_password('Tx@123456');
        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertNotSame('Tx@123456', $hash);
        $this->assertTrue(verify_password('Tx@123456', $hash));
        $this->assertFalse(verify_password('Tx@123457', $hash));
        // 同一口令两次哈希不同（带盐），库内无法比对出重复口令
        $this->assertNotSame($hash, hash_password('Tx@123456'));
    }

    public function testAesRoundTripHandlesShortAndLongPlaintext(): void
    {
        foreach (['张三', '110101199003077578', '阿·买买提·艾力'] as $plain) {
            $cipher = aes_encrypt($plain);
            $this->assertNotSame($plain, $cipher);
            $this->assertSame($plain, aes_decrypt($cipher), '短实名曾被 48 字节下限误杀，解密必须还原');
        }
    }

    public function testAesDecryptRejectsNonCiphertext(): void
    {
        $this->assertNull(aes_decrypt(''));
        $this->assertNull(aes_decrypt('plain-text-not-encrypted'));
        // 长度够但不是本密钥的密文：解密失败应返回 null，而不是抛出或返回乱码
        $this->assertNull(aes_decrypt(base64_encode(str_repeat('x', 64))));
    }

    public function testAppKeyFallsBackToBuiltInKeyOnlyInDebugMode(): void
    {
        // .env 的 APP_KEY 为空 + APP_DEBUG=true 才允许回落；生产环境的 fail-closed 见 app_key()
        $this->assertSame('sinan-nft-secret-key-2026', app_key());
    }

    // ============================================================
    // 昵称合规
    // ============================================================

    public function testEmptyNicknameIsRejected(): void
    {
        foreach (['', '   ', "\t"] as $raw) {
            $result = filter_nickname($raw);
            $this->assertFalse($result['ok']);
            $this->assertSame('昵称为空', $result['reason']);
        }
    }

    public function testHtmlTagsAreStrippedBeforeValidation(): void
    {
        $result = filter_nickname('<b>阿蛮</b>');
        $this->assertTrue($result['ok']);
        $this->assertSame('阿蛮', $result['nickname']);
    }

    public function testSensitiveWordIsMaskedNotSilentlyAccepted(): void
    {
        $result = filter_nickname('我是管理员');
        $this->assertTrue($result['ok']);
        $this->assertSame('我是***', $result['nickname']);
        $this->assertSame(['管理员'], $result['hit']);
        $this->assertStringContainsString('已自动打码', $result['reason']);

        $this->assertSame([], filter_nickname('正常昵称')['hit']);
    }

    /** 词表存在包含关系时（司南官方 / 官方客服），重叠部分只会被先命中的词替换一次 */
    public function testOverlappingSensitiveWordsStillLeaveResidue(): void
    {
        $result = filter_nickname('司南官方客服');
        $this->assertSame('****客服', $result['nickname']);
        $this->assertSame(['司南官方'], $result['hit']);
    }

    public function testNicknameMadeEntirelyOfStarsIsRejected(): void
    {
        $result = filter_nickname('SB');
        $this->assertFalse($result['ok']);
        $this->assertSame('昵称包含不允许的敏感内容', $result['reason']);
    }

    public function testNicknameLengthBoundsAreEnforcedAfterMasking(): void
    {
        $tooShort = filter_nickname('a');
        $this->assertFalse($tooShort['ok']);
        $this->assertSame('昵称过短（过滤后不足 2 字）', $tooShort['reason']);

        $tooLong = filter_nickname(str_repeat('南', 21));
        $this->assertFalse($tooLong['ok']);
        $this->assertSame('昵称过长（超过 20 字）', $tooLong['reason']);

        $this->assertTrue(filter_nickname(str_repeat('南', 20))['ok']);
    }

    public function testSensitiveWordListHasNoDuplicatesOrEmptyEntries(): void
    {
        $words = sensitive_words();
        $this->assertNotEmpty($words);
        $this->assertCount(count(array_unique($words)), $words);
        foreach ($words as $word) {
            $this->assertNotSame('', $word);
        }
    }
}
