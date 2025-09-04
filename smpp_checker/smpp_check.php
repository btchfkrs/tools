#!/usr/bin/env php
<?php
/**
 * smpp_check.php — Minimal SMPP connectivity & bind checker (PHP 7.4+)
 *
 * Features:
 * - Plain TCP or TLS (STARTTLS not supported; use direct TLS socket)
 * - Bind as transmitter/receiver/transceiver
 * - Logs to file and STDOUT: TCP errors, SMPP status codes, hex PDUs
 * - Optional enquire_link after bind
 *
 * Usage:
 *   php smpp_check.php \
 *     --host=127.0.0.1 --port=2775 \
 *     --system_id=USER --password=PASS \
 *     [--system_type=] [--bind=trx|tx|rx] \
 *     [--addr_ton=0] [--addr_npi=0] [--address_range=] \
 *     [--timeout=10] [--read_timeout=10] [--enquire] \
 *     [--tls] [--log=/path/to/file.log] [--verbose]
 *
 * Examples:
 *   php smpp_check.php --host=smsc.example.com --port=2775 --system_id=foo --password=bar --bind=trx --enquire --verbose
 *   php smpp_check.php --host=smsc.example.com --port=3550 --system_id=foo --password=bar --bind=tx --tls --log=/var/log/smpp_check.log
 */

ini_set('display_errors', 'stderr');
date_default_timezone_set('UTC');

$opts = getopt('', [
    'host:', 'port:', 'system_id:', 'password:',
    'system_type::', 'bind::', 'addr_ton::', 'addr_npi::', 'address_range::',
    'timeout::', 'read_timeout::', 'enquire::', 'tls::', 'log::', 'verbose::'
]);

function usage($msg = '') {
    if ($msg) fwrite(STDERR, "Error: $msg\n");
    fwrite(STDERR, "See header of this script for usage.\n");
    exit(2);
}

$host = $opts['host'] ?? usage('Missing --host');
$port = isset($opts['port']) ? intval($opts['port']) : 2775;
$system_id = $opts['system_id'] ?? usage('Missing --system_id');
$password = $opts['password'] ?? usage('Missing --password');
$system_type = $opts['system_type'] ?? '';
$bindMode = strtolower($opts['bind'] ?? 'trx'); // trx|tx|rx
$addr_ton = isset($opts['addr_ton']) ? intval($opts['addr_ton']) : 0;
$addr_npi = isset($opts['addr_npi']) ? intval($opts['addr_npi']) : 0;
$address_range = $opts['address_range'] ?? '';
$timeout = isset($opts['timeout']) ? intval($opts['timeout']) : 10; // TCP connect timeout
$readTimeout = isset($opts['read_timeout']) ? intval($opts['read_timeout']) : 10; // read PDU timeout
$doEnquire = array_key_exists('enquire', $opts); // flag if present
$useTLS = array_key_exists('tls', $opts); // flag if present
$logfile = $opts['log'] ?? null;
$verbose = array_key_exists('verbose', $opts);

$CMD = [
    'bind_transmitter'  => 0x00000002,
    'bind_receiver'     => 0x00000001,
    'bind_transceiver'  => 0x00000009,
    'unbind'            => 0x00000006,
    'enquire_link'      => 0x00000015,
];
$RESP = 0x80000000;

$ESME_STATUS = [
    0x00000000 => 'ESME_ROK (OK)',
    0x00000001 => 'ESME_RINVMSGLEN',
    0x00000002 => 'ESME_RINVCMDLEN',
    0x00000003 => 'ESME_RINVCMDID',
    0x00000004 => 'ESME_RINVBNDSTS',
    0x00000005 => 'ESME_RALYBND',
    0x0000000A => 'ESME_RSYSERR',
    0x0000000E => 'ESME_RINVSRCADR',
    0x0000000F => 'ESME_RINVDSTADR',
    0x00000033 => 'ESME_RINVSYSID',
    0x00000034 => 'ESME_RINVPASWD',
    0x00000035 => 'ESME_RINVSYS_TYPE',
    0x00000040 => 'ESME_RINVBIND (generic)',
    0x00000058 => 'ESME_RBINDFAIL',
    // ... add more as needed
];

function logmsg($level, $msg, $logfile, $stdoutAlso = true) {
    $line = sprintf("[%s] %-5s %s\n", date('Y-m-d H:i:s'), strtoupper($level), $msg);
    if ($stdoutAlso) fwrite(STDOUT, $line);
    if ($logfile) file_put_contents($logfile, $line, FILE_APPEND);
}

function hex_dump($data, $max = 1024) {
    $slice = substr($data, 0, $max);
    return strtoupper(implode('', unpack('H*', $slice)));
}

function cstring($s) {
    return $s . "\x00";
}

function pdu_pack($command_id, $sequence_number, $body) {
    $len = 16 + strlen($body);
    return pack('N4', $len, $command_id, 0, $sequence_number) . $body;
}

function read_exact($fp, $len, $readTimeout) {
    $data = '';
    while (strlen($data) < $len) {
        $r = [$fp]; $w = null; $e = null;
        $n = stream_select($r, $w, $e, $readTimeout);
        if ($n === false) throw new RuntimeException("stream_select failed");
        if ($n === 0) throw new RuntimeException("read timeout after {$readTimeout}s");
        $chunk = fread($fp, $len - strlen($data));
        if ($chunk === '' || $chunk === false) throw new RuntimeException("connection closed while reading");
        $data .= $chunk;
    }
    return $data;
}

function read_pdu($fp, $readTimeout) {
    $hdr = read_exact($fp, 4, $readTimeout);
    $unpacked = unpack('Nlen', $hdr);
    $len = $unpacked['len'];
    if ($len < 16) throw new RuntimeException("invalid PDU length $len");
    $rest = read_exact($fp, $len - 4, $readTimeout);
    $all = $hdr . $rest;
    $parts = unpack('Nlen/Ncmd/Nstatus/Nseq', $all);
    $body = substr($all, 16);
    return [$parts, $body, $all];
}

function build_bind_body($system_id, $password, $system_type, $addr_ton, $addr_npi, $address_range) {
    $interface_version = chr(0x34); // SMPP v3.4
    return cstring($system_id)
         . cstring($password)
         . cstring($system_type)
         . $interface_version
         . chr($addr_ton)
         . chr($addr_npi)
         . cstring($address_range);
}

function smpp_connect($host, $port, $timeout, $useTLS) {
    $scheme = $useTLS ? 'tls' : 'tcp';
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client("$scheme://$host:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'SNI_enabled' => true,
            'capture_peer_cert' => false,
        ]
    ]));
    if (!$fp) {
        throw new RuntimeException("TCP connect failed: $errstr (code $errno)");
    }
    stream_set_timeout($fp, $timeout);
    stream_set_blocking($fp, true);
    return $fp;
}

function smpp_unbind($fp, $CMD, $RESP, $readTimeout, $logfile, $verbose) {
    static $seq = 1000;
    $seq++;
    $pdu = pdu_pack($CMD['unbind'], $seq, '');
    logmsg('info', "Sending UNBIND seq=$seq PDU=" . hex_dump($pdu), $logfile, $verbose);
    fwrite($fp, $pdu);
    try {
        [$hdr, $body, $raw] = read_pdu($fp, $readTimeout);
        logmsg('info', sprintf("Got UNBIND_RESP seq=%d status=0x%08X raw=%s", $hdr['seq'], $hdr['status'], hex_dump($raw)), $logfile, $verbose);
    } catch (Throwable $e) {
        logmsg('warn', "UNBIND response not received: " . $e->getMessage(), $logfile, true);
    }
}

try {
    if (!in_array($bindMode, ['trx','tx','rx'], true)) usage('--bind must be trx|tx|rx');
    if ($logfile) {
        // touch logfile early to ensure permissions
        @file_put_contents($logfile, sprintf("[%s] ----- smpp_check start -----\n", date('Y-m-d H:i:s')), FILE_APPEND);
    }

    logmsg('info', sprintf(
        "Connecting to %s:%d (TLS=%s, timeout=%ds) bind=%s system_id=%s",
        $host, $port, $useTLS ? 'yes' : 'no', $timeout, strtoupper($bindMode), $system_id
    ), $logfile, true);

    $fp = smpp_connect($host, $port, $timeout, $useTLS);
    logmsg('info', "TCP connected", $logfile, true);

    // Prepare bind PDU
    $cmdId = [
        'tx'  => $CMD['bind_transmitter'],
        'rx'  => $CMD['bind_receiver'],
        'trx' => $CMD['bind_transceiver'],
    ][$bindMode];

    static $seq = 1;
    $body = build_bind_body($system_id, $password, $system_type, $addr_ton, $addr_npi, $address_range);
    $pdu  = pdu_pack($cmdId, $seq, $body);

    logmsg('info', "Sending " . strtoupper($bindMode) . " BIND seq=$seq", $logfile, true);
    if ($verbose) logmsg('debug', "BIND PDU HEX=" . hex_dump($pdu), $logfile, true);

    fwrite($fp, $pdu);

    // Read bind_resp
    [$hdr, $respBody, $raw] = read_pdu($fp, $readTimeout);
    $status = $hdr['status'];
    $statusStr = $ESME_STATUS[$status] ?? sprintf("UNKNOWN(0x%08X)", $status);
    logmsg($status === 0 ? 'info' : 'error',
        sprintf("BIND_RESP seq=%d status=%s", $hdr['seq'], $statusStr),
        $logfile,
        true
    );
    if ($verbose) logmsg('debug', "BIND_RESP HEX=" . hex_dump($raw), $logfile, true);

    if ($status !== 0) {
        // SMPP refused the bind: exit non-zero
        fclose($fp);
        exit(1);
    }

    // Optionally send enquire_link to verify liveness
    if ($doEnquire) {
        $seq++;
        $enq = pdu_pack($CMD['enquire_link'], $seq, '');
        logmsg('info', "Sending ENQUIRE_LINK seq=$seq", $logfile, true);
        if ($verbose) logmsg('debug', "ENQUIRE_LINK HEX=" . hex_dump($enq), $logfile, true);
        fwrite($fp, $enq);
        [$eh, $ebody, $eraw] = read_pdu($fp, $readTimeout);
        if ($eh['cmd'] !== ($CMD['enquire_link'] | $RESP)) {
            logmsg('warn', sprintf("Unexpected response to ENQUIRE_LINK: cmd=0x%08X", $eh['cmd']), $logfile, true);
        } else {
            logmsg('info', sprintf("ENQUIRE_LINK_RESP received seq=%d status=0x%08X", $eh['seq'], $eh['status']), $logfile, true);
            if ($verbose) logmsg('debug', "ENQUIRE_LINK_RESP HEX=" . hex_dump($eraw), $logfile, true);
        }
    }

    // Clean unbind
    smpp_unbind($fp, $CMD, $RESP, $readTimeout, $logfile, $verbose);
    fclose($fp);
    logmsg('info', "SMPP check completed successfully.", $logfile, true);
    exit(0);

} catch (Throwable $e) {
    logmsg('error', $e->getMessage(), $logfile, true);
    // Try to unbind/close if possible
    if (isset($fp) && is_resource($fp)) {
        @smpp_unbind($fp, $CMD, $RESP, $readTimeout, $logfile, false);
        @fclose($fp);
    }
    exit(1);
}
