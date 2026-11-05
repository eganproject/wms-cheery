<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Membaca nama paket, versionCode, dan versionName dari berkas APK.
 *
 * APK adalah ZIP; AndroidManifest.xml di dalamnya berformat XML biner
 * Android (AXML). Yang dibaca hanya atribut elemen <manifest>.
 */
class ApkInfo
{
    private const CHUNK_STRING_POOL = 0x0001;

    private const CHUNK_XML = 0x0003;

    private const CHUNK_RESOURCE_MAP = 0x0180;

    private const CHUNK_START_ELEMENT = 0x0102;

    private const ATTR_VERSION_CODE = 0x0101021B;

    private const ATTR_VERSION_NAME = 0x0101021C;

    private const TYPE_STRING = 0x03;

    private const TYPE_INT_DEC = 0x10;

    private const TYPE_INT_HEX = 0x11;

    public function __construct(
        public readonly string $package,
        public readonly int $versionCode,
        public readonly ?string $versionName,
    ) {}

    /**
     * @throws RuntimeException bila berkas bukan APK yang bisa dibaca
     */
    public static function read(string $path): self
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Berkas bukan APK (tidak bisa dibuka sebagai ZIP).');
        }

        $manifest = $zip->getFromName('AndroidManifest.xml');
        $zip->close();

        if ($manifest === false || strlen($manifest) < 8) {
            throw new RuntimeException('AndroidManifest.xml tidak ditemukan di dalam APK.');
        }

        return self::parseManifest($manifest);
    }

    public static function parseManifest(string $data): self
    {
        $length = strlen($data);
        if (self::u16($data, 0) !== self::CHUNK_XML) {
            throw new RuntimeException('Manifest APK tidak dikenali.');
        }

        $strings = [];
        $resourceIds = [];
        $offset = self::u16($data, 2);

        while ($offset + 8 <= $length) {
            $type = self::u16($data, $offset);
            $headerSize = self::u16($data, $offset + 2);
            $size = self::u32($data, $offset + 4);
            if ($size < 8 || $offset + $size > $length) {
                break;
            }

            if ($type === self::CHUNK_STRING_POOL) {
                $strings = self::stringPool($data, $offset);
            } elseif ($type === self::CHUNK_RESOURCE_MAP) {
                for ($i = $offset + $headerSize; $i + 4 <= $offset + $size; $i += 4) {
                    $resourceIds[] = self::u32($data, $i);
                }
            } elseif ($type === self::CHUNK_START_ELEMENT) {
                $nameIndex = self::u32($data, $offset + 20);
                if (($strings[$nameIndex] ?? null) === 'manifest') {
                    return self::manifestElement($data, $offset, $strings, $resourceIds);
                }
            }

            $offset += $size;
        }

        throw new RuntimeException('Elemen <manifest> tidak ditemukan di APK.');
    }

    /**
     * @param  array<int, string>  $strings
     * @param  array<int, int>  $resourceIds
     */
    private static function manifestElement(string $data, int $offset, array $strings, array $resourceIds): self
    {
        $attributeStart = self::u16($data, $offset + 24);
        $attributeSize = self::u16($data, $offset + 26) ?: 20;
        $attributeCount = self::u16($data, $offset + 28);
        $base = $offset + 16 + $attributeStart;

        $package = null;
        $versionCode = null;
        $versionName = null;

        for ($i = 0; $i < $attributeCount; $i++) {
            $at = $base + $i * $attributeSize;
            $nameIndex = self::u32($data, $at + 4);
            $raw = self::u32($data, $at + 8);
            $dataType = ord($data[$at + 15]);
            $value = self::u32($data, $at + 16);

            $name = $strings[$nameIndex] ?? null;
            $resourceId = $resourceIds[$nameIndex] ?? null;

            $string = $dataType === self::TYPE_STRING
                ? ($strings[$value] ?? null)
                : ($raw !== 0xFFFFFFFF ? ($strings[$raw] ?? null) : null);

            if ($resourceId === self::ATTR_VERSION_CODE || $name === 'versionCode') {
                $versionCode = in_array($dataType, [self::TYPE_INT_DEC, self::TYPE_INT_HEX], true)
                    ? $value
                    : (int) $string;
            } elseif ($resourceId === self::ATTR_VERSION_NAME || $name === 'versionName') {
                $versionName = $string ?? (string) $value;
            } elseif ($name === 'package') {
                $package = $string;
            }
        }

        if (! $package || ! $versionCode) {
            throw new RuntimeException('Nama paket atau nomor versi tidak terbaca dari APK.');
        }

        return new self($package, $versionCode, $versionName);
    }

    /** @return array<int, string> */
    private static function stringPool(string $data, int $offset): array
    {
        $headerSize = self::u16($data, $offset + 2);
        $count = self::u32($data, $offset + 8);
        $flags = self::u32($data, $offset + 16);
        $stringsStart = $offset + self::u32($data, $offset + 20);
        $utf8 = ($flags & 0x100) !== 0;

        $strings = [];
        for ($i = 0; $i < $count; $i++) {
            $at = $stringsStart + self::u32($data, $offset + $headerSize + $i * 4);

            if ($utf8) {
                // Panjang UTF-16 lalu panjang UTF-8, masing-masing 1-2 bita.
                $at += (ord($data[$at]) & 0x80) ? 2 : 1;
                $len = ord($data[$at]);
                if ($len & 0x80) {
                    $len = (($len & 0x7F) << 8) | ord($data[$at + 1]);
                    $at += 2;
                } else {
                    $at += 1;
                }
                $strings[$i] = substr($data, $at, $len);
            } else {
                $len = self::u16($data, $at);
                if ($len & 0x8000) {
                    $len = (($len & 0x7FFF) << 16) | self::u16($data, $at + 2);
                    $at += 4;
                } else {
                    $at += 2;
                }
                $strings[$i] = mb_convert_encoding(substr($data, $at, $len * 2), 'UTF-8', 'UTF-16LE');
            }
        }

        return $strings;
    }

    private static function u16(string $data, int $at): int
    {
        return unpack('v', $data, $at)[1];
    }

    private static function u32(string $data, int $at): int
    {
        return unpack('V', $data, $at)[1];
    }
}
