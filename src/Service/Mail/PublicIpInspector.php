<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Positive-allowlist IP classifier: returns true only for addresses that are provably
 * global-unicast (safe to open an outbound SMTP socket to). Works on the packed bytes
 * (inet_pton), so it sees IPv4 embedded in IPv6 (mapped / 6to4 / Teredo) that textual
 * FILTER_FLAG_* checks miss.
 */
final readonly class PublicIpInspector
{
    private const int V4_BYTE_COUNT = 4;

    private const int V6_BYTE_COUNT = 16;

    // ---------------------------------------------------------------------------
    // IPv4 first-octet boundaries
    // ---------------------------------------------------------------------------

    /** 0.0.0.0/8 — "this" network / unspecified */
    private const int IPV4_UNSPECIFIED_FIRST = 0;

    /** 10.0.0.0/8 — RFC 1918 private-A */
    private const int IPV4_PRIVATE_A_FIRST = 10;

    /** 100.64.0.0/10 CGNAT (RFC 6598) — first octet */
    private const int IPV4_CGNAT_FIRST = 100;

    /** 127.0.0.0/8 — loopback */
    private const int IPV4_LOOPBACK_FIRST = 127;

    /** 169.254.0.0/16 — link-local; first octet */
    private const int IPV4_LINK_LOCAL_FIRST = 169;

    /** 172.16.0.0/12 — RFC 1918 private-B; first octet */
    private const int IPV4_PRIVATE_B_FIRST = 172;

    /** 192.x.x.x blocks (0/24, 0.2/24, 88.99/24, 168/16) */
    private const int IPV4_192_FIRST = 192;

    /** 198.x.x.x blocks (18.0/15 benchmark, 51.100/24 TEST-NET-2) */
    private const int IPV4_198_FIRST = 198;

    /** 203.0.113.0/24 TEST-NET-3 */
    private const int IPV4_TEST_NET_3_FIRST = 203;

    /** 224.0.0.0/3 — start of multicast + reserved range; anything ≥ this is non-public */
    private const int IPV4_MULTICAST_RESERVED_START = 224;

    // ---------------------------------------------------------------------------
    // IPv4 second-octet boundaries used inside specific first-octet blocks
    // ---------------------------------------------------------------------------

    /** 169.254.x.x — link-local second octet */
    private const int IPV4_LINK_LOCAL_SECOND = 254;

    /** 172.16.0.0/12 — second-octet lower bound */
    private const int IPV4_PRIVATE_B_SECOND_LOW = 16;

    /** 172.16.0.0/12 — second-octet upper bound (172.31.x.x) */
    private const int IPV4_PRIVATE_B_SECOND_HIGH = 31;

    /** 100.64.0.0/10 CGNAT — second-octet lower bound */
    private const int IPV4_CGNAT_SECOND_LOW = 64;

    /** 100.64.0.0/10 CGNAT — second-octet upper bound (100.127.x.x) */
    private const int IPV4_CGNAT_SECOND_HIGH = 127;

    /** 192.88.99.0/24 (deprecated 6to4 anycast relay) — second octet */
    private const int IPV4_6TO4_ANYCAST_SECOND = 88;

    /** 192.88.99.0/24 — third octet */
    private const int IPV4_6TO4_ANYCAST_THIRD = 99;

    /** 192.168.0.0/16 — RFC 1918 private-C; second octet */
    private const int IPV4_PRIVATE_C_SECOND = 168;

    /** 198.18.0.0/15 benchmark — second-octet lower bound */
    private const int IPV4_BENCHMARK_SECOND_LOW = 18;

    /** 198.18.0.0/15 benchmark — second-octet upper bound */
    private const int IPV4_BENCHMARK_SECOND_HIGH = 19;

    /** 198.51.100.0/24 TEST-NET-2 — second octet */
    private const int IPV4_TEST_NET_2_SECOND = 51;

    /** 198.51.100.0/24 TEST-NET-2 — third octet */
    private const int IPV4_TEST_NET_2_THIRD = 100;

    /** 203.0.113.0/24 TEST-NET-3 — second octet */
    private const int IPV4_TEST_NET_3_SECOND = 0;

    /** 203.0.113.0/24 TEST-NET-3 — third octet */
    private const int IPV4_TEST_NET_3_THIRD = 113;

    /** 192.0.2.0/24 IETF Protocol Assignments (TEST-NET-1) — third octet */
    private const int IPV4_IETF_PROTOCOL_THIRD = 2;

    // ---------------------------------------------------------------------------
    // IPv6 byte constants (hex literals)
    // ---------------------------------------------------------------------------

    /** IPv4-mapped ::ffff:0:0/96 — bytes 10 and 11 are 0xff */
    private const int IPV6_MAPPED_MARKER_BYTE = 0xff;

    /** 6to4 2002::/16 — byte 0 = 0x20 */
    private const int IPV6_6TO4_BYTE0 = 0x20;

    /** 6to4 2002::/16 — byte 1 = 0x02 */
    private const int IPV6_6TO4_BYTE1 = 0x02;

    /** Teredo 2001:0000::/32 — byte 0 = 0x20 */
    private const int IPV6_TEREDO_BYTE0 = 0x20;

    /** Teredo 2001:0000::/32 — byte 1 = 0x01 */
    private const int IPV6_TEREDO_BYTE1 = 0x01;

    /** Teredo — XOR mask applied to bytes 12–15 to recover the embedded IPv4 */
    private const int IPV6_TEREDO_XOR_MASK = 0xff;

    /** fe80::/10 link-local — byte 0 = 0xfe */
    private const int IPV6_LINK_LOCAL_HIGH_BYTE = 0xfe;

    /** fe80::/10 link-local — mask for the /10 prefix check on byte 1 */
    private const int IPV6_LINK_LOCAL_MASK = 0xc0;

    /** fe80::/10 link-local — expected result after masking byte 1 */
    private const int IPV6_LINK_LOCAL_TAG = 0x80;

    /** fc00::/7 ULA — mask for the /7 prefix check on byte 0 */
    private const int IPV6_ULA_MASK = 0xfe;

    /** fc00::/7 ULA — expected result after masking byte 0 */
    private const int IPV6_ULA_TAG = 0xfc;

    /** ff00::/8 multicast — byte 0 = 0xff */
    private const int IPV6_MULTICAST_BYTE = 0xff;

    /** 2000::/3 global unicast — mask for the /3 prefix check on byte 0 */
    private const int IPV6_GLOBAL_UNICAST_MASK = 0xe0;

    /** 2000::/3 global unicast — expected result after masking byte 0 */
    private const int IPV6_GLOBAL_UNICAST_TAG = 0x20;

    public function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        return match (\strlen($packed)) {
            self::V4_BYTE_COUNT => $this->isPublicV4($packed),
            self::V6_BYTE_COUNT => $this->isPublicV6($packed),
            default => false,
        };
    }

    private function isPublicV4(string $packed): bool
    {
        /** @var list<int> $b */
        $b = array_values((array) unpack('C4', $packed));

        // 0.0.0.0/8 (incl. unspecified)
        if ($b[0] === self::IPV4_UNSPECIFIED_FIRST) {
            return false;
        }

        // 10.0.0.0/8
        if ($b[0] === self::IPV4_PRIVATE_A_FIRST) {
            return false;
        }

        // 100.64.0.0/10 CGNAT (RFC 6598)
        if (
            $b[0] === self::IPV4_CGNAT_FIRST
            && $b[1] >= self::IPV4_CGNAT_SECOND_LOW
            && $b[1] <= self::IPV4_CGNAT_SECOND_HIGH
        ) {
            return false;
        }

        // 127.0.0.0/8 loopback
        if ($b[0] === self::IPV4_LOOPBACK_FIRST) {
            return false;
        }

        // 169.254.0.0/16 link-local
        if ($b[0] === self::IPV4_LINK_LOCAL_FIRST && $b[1] === self::IPV4_LINK_LOCAL_SECOND) {
            return false;
        }

        // 172.16.0.0/12
        if (
            $b[0] === self::IPV4_PRIVATE_B_FIRST
            && $b[1] >= self::IPV4_PRIVATE_B_SECOND_LOW
            && $b[1] <= self::IPV4_PRIVATE_B_SECOND_HIGH
        ) {
            return false;
        }

        // 192.0.0.0/24, 192.0.2.0/24, 192.88.99.0/24, 192.168.0.0/16
        if ($b[0] === self::IPV4_192_FIRST) {
            if (
                $b[1] === self::IPV4_UNSPECIFIED_FIRST
                && ($b[2] === self::IPV4_UNSPECIFIED_FIRST || $b[2] === self::IPV4_IETF_PROTOCOL_THIRD)
            ) {
                return false;
            }

            if ($b[1] === self::IPV4_6TO4_ANYCAST_SECOND && $b[2] === self::IPV4_6TO4_ANYCAST_THIRD) {
                return false;
            }

            if ($b[1] === self::IPV4_PRIVATE_C_SECOND) {
                return false;
            }
        }

        // 198.18.0.0/15 benchmark, 198.51.100.0/24 TEST-NET-2
        if ($b[0] === self::IPV4_198_FIRST) {
            if ($b[1] === self::IPV4_BENCHMARK_SECOND_LOW || $b[1] === self::IPV4_BENCHMARK_SECOND_HIGH) {
                return false;
            }

            if ($b[1] === self::IPV4_TEST_NET_2_SECOND && $b[2] === self::IPV4_TEST_NET_2_THIRD) {
                return false;
            }
        }

        // 203.0.113.0/24 TEST-NET-3
        if (
            $b[0] === self::IPV4_TEST_NET_3_FIRST
            && $b[1] === self::IPV4_TEST_NET_3_SECOND
            && $b[2] === self::IPV4_TEST_NET_3_THIRD
        ) {
            return false;
        }

        // 224.0.0.0/3 — multicast (224/4) + reserved (240/4) + 255.255.255.255
        return $b[0] < self::IPV4_MULTICAST_RESERVED_START;
    }

    private function isPublicV6(string $packed): bool
    {
        /** @var list<int> $b */
        $b = array_values((array) unpack('C16', $packed));

        // IPv4-mapped ::ffff:0:0/96
        if (
            $this->zeroRange($b, 0, 9)
            && $b[10] === self::IPV6_MAPPED_MARKER_BYTE
            && $b[11] === self::IPV6_MAPPED_MARKER_BYTE
        ) {
            return $this->isPublicV4(pack('C4', $b[12], $b[13], $b[14], $b[15]));
        }

        // deprecated IPv4-compatible ::/96 (non-zero tail) — also catches ::1
        if ($this->zeroRange($b, 0, 11) && !$this->zeroRange($b, 12, 15)) {
            return $this->isPublicV4(pack('C4', $b[12], $b[13], $b[14], $b[15]));
        }

        // 6to4 2002::/16 — embedded v4 in bytes 2..5
        if ($b[0] === self::IPV6_6TO4_BYTE0 && $b[1] === self::IPV6_6TO4_BYTE1) {
            return $this->isPublicV4(pack('C4', $b[2], $b[3], $b[4], $b[5]));
        }

        // Teredo 2001:0000::/32 — embedded v4 in bytes 12..15, bitwise-NOT obfuscated
        if (
            $b[0] === self::IPV6_TEREDO_BYTE0
            && $b[1] === self::IPV6_TEREDO_BYTE1
            && $b[2] === self::IPV4_UNSPECIFIED_FIRST
            && $b[3] === self::IPV4_UNSPECIFIED_FIRST
        ) {
            return $this->isPublicV4(
                pack(
                    'C4',
                    $b[12] ^ self::IPV6_TEREDO_XOR_MASK,
                    $b[13] ^ self::IPV6_TEREDO_XOR_MASK,
                    $b[14] ^ self::IPV6_TEREDO_XOR_MASK,
                    $b[15] ^ self::IPV6_TEREDO_XOR_MASK,
                ),
            );
        }

        // :: unspecified
        if ($this->zeroRange($b, 0, 15)) {
            return false;
        }

        // fe80::/10 link-local
        if (
            $b[0] === self::IPV6_LINK_LOCAL_HIGH_BYTE
            && ($b[1] & self::IPV6_LINK_LOCAL_MASK) === self::IPV6_LINK_LOCAL_TAG
        ) {
            return false;
        }

        // fc00::/7 ULA
        if (($b[0] & self::IPV6_ULA_MASK) === self::IPV6_ULA_TAG) {
            return false;
        }

        // ff00::/8 multicast
        if ($b[0] === self::IPV6_MULTICAST_BYTE) {
            return false;
        }

        // require 2000::/3 global unicast
        return ($b[0] & self::IPV6_GLOBAL_UNICAST_MASK) === self::IPV6_GLOBAL_UNICAST_TAG;
    }

    /** @param list<int> $b */
    private function zeroRange(array $b, int $from, int $to): bool
    {
        for ($i = $from; $i <= $to; ++$i) {
            if ($b[$i] !== 0) {
                return false;
            }
        }

        return true;
    }
}
