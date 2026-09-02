<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Prints the addresses this machine is currently reachable on.
 *
 * During the pilot the school server runs on a laptop, and its LAN address
 * changes whenever the DHCP lease does. That is not a rare event — it moved
 * three times in two days, and each move silently broke every handset, because
 * the only symptom is a timeout on the login screen.
 *
 *   php artisan school:where-am-i
 */
class WhereAmI extends Command
{
    protected $signature = 'school:where-am-i {--port=8000 : The port artisan serve is bound to}';

    protected $description = 'Print the LAN address the phones should be pointed at';

    public function handle(): int
    {
        $port = (int) $this->option('port');
        $addresses = $this->lanAddresses();

        $this->newLine();
        $this->line('  <fg=gray>Dashboard, on this machine — never changes:</>');
        $this->line("  <fg=cyan>http://localhost:{$port}/</>");
        $this->newLine();

        if (! $addresses) {
            $this->warn('  No LAN address found. This machine is probably not on a network,');
            $this->warn('  so no handset can reach it whatever the apps are pointed at.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->line('  <fg=gray>For the phones — must be on the SAME Wi-Fi:</>');

        foreach ($addresses as $ip) {
            $this->line("  <fg=green>http://{$ip}:{$port}</>");
        }

        $primary = $addresses[0];

        $this->newLine();
        $this->line('  <fg=gray>Type that into the app\'s "Server" field on the login screen.</>');
        $this->line('  <fg=gray>No rebuild needed — it is stored on the device.</>');
        $this->newLine();
        $this->line('  <fg=gray>Or to bake it into a fresh build:</>');
        $this->line("  <fg=yellow>--dart-define=ZIPPI_API_BASE=http://{$primary}:{$port}</>");
        $this->newLine();

        // ⚠ Being reachable on an address is not the same as a phone being able
        // to USE it. Office and campus access points often isolate clients from
        // each other, and the failure looks identical to a wrong address.
        if (str_starts_with($primary, '172.') || str_starts_with($primary, '10.')) {
            $this->line('  <fg=gray>If a phone on the same Wi-Fi still cannot open that URL in its</>');
            $this->line('  <fg=gray>browser, the access point is isolating clients — not your app.</>');
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * IPv4 addresses on real interfaces, loopback and link-local excluded.
     *
     * Shells out rather than using php_uname/gethostbyname: the hostname of a
     * Mac frequently resolves to 127.0.0.1, which is exactly the wrong answer
     * to hand somebody who is about to type it into a phone.
     */
    private function lanAddresses(): array
    {
        $out = [];

        if (str_contains(strtolower(PHP_OS_FAMILY), 'darwin') || PHP_OS_FAMILY === 'Darwin') {
            foreach (['en0', 'en1', 'en2'] as $if) {
                $ip = trim((string) @shell_exec("ipconfig getifaddr {$if} 2>/dev/null"));
                if ($ip !== '') $out[] = $ip;
            }
        }

        if (! $out) {
            $raw = (string) @shell_exec("ifconfig 2>/dev/null | grep 'inet ' || ip -4 addr 2>/dev/null");
            preg_match_all('/inet\s+(\d+\.\d+\.\d+\.\d+)/', $raw, $m);
            $out = $m[1] ?? [];
        }

        return array_values(array_filter(array_unique($out), function (string $ip) {
            return $ip !== '127.0.0.1'
                && ! str_starts_with($ip, '127.')
                && ! str_starts_with($ip, '169.254.');   // link-local: never routable
        }));
    }
}
