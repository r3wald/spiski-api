<?php

declare(strict_types=1);

namespace App\Status;

/**
 * Rendert `/status.html`: dieselben Daten wie `/status.json`, als reines HTML ohne
 * JavaScript. Die Seite laedt sich per `<meta http-equiv="refresh">` selbst neu.
 *
 * @phpstan-import-type Status from StatusReader
 */
final class StatusPageRenderer
{
    public const REFRESH_SECONDS = 60;

    private const LABELS = [
        'ok' => 'Alle Systeme betriebsbereit',
        'degraded' => 'Eingeschränkt',
        'down' => 'Gestört',
    ];

    /**
     * @param Status $status
     */
    public function render(array $status): string
    {
        $api = $status['api'];
        $db = $status['database'];
        $migrations = $status['migrations'];

        $apiRows = $this->row('Version', '<code>'.$this->e($api['version']).'</code>')
            .$this->row('Revision', null === $api['revision'] ? $this->unknown() : '<code>'.$this->e($api['revision']).'</code>')
            .$this->row('Deployt (UTC)', null === $api['deployedAt'] ? $this->unknown() : $this->time($api['deployedAt']));

        $dbRows = $this->row('Datenbank', $this->badge('up' === $db['status'] ? 'ok' : 'down', 'up' === $db['status'] ? 'erreichbar' : 'nicht erreichbar'))
            .(null === $db['latencyMs'] ? '' : $this->row('Antwortzeit', $this->e(number_format($db['latencyMs'], 1, ',', '.')).' ms'))
            .$this->row('Schema', match ($migrations['status']) {
                'current' => $this->badge('ok', 'aktuell'),
                'pending' => $this->badge('degraded', \sprintf('%d %s ausstehend', $migrations['pending'] ?? 0, 1 === $migrations['pending'] ? 'Migration' : 'Migrationen')),
                'unknown' => $this->badge('down', 'unbekannt'),
            })
            .(null === $migrations['current'] ? '' : $this->row('Letzte Migration', '<code>'.$this->e($migrations['current']).'</code>'));

        $stats = $status['statistics'];
        $usage = null === $stats
            ? '<p class="muted">Nicht verfügbar, solange die Datenbank nicht erreichbar ist.</p>'
            : '<dl>'
                .$this->row('Konten', $this->n($stats['accounts']['total']))
                .$this->row('aktiv in 24 h / 7 Tagen / 30 Tagen', $this->n($stats['accounts']['active24h']).' / '.$this->n($stats['accounts']['active7d']).' / '.$this->n($stats['accounts']['active30d']))
                .$this->row('Listen', $this->n($stats['lists']['active']).' <span class="muted">(+ '.$this->n($stats['lists']['deleted']).' gelöscht)</span>')
                .$this->row('davon geteilt', $this->n($stats['lists']['shared']).' <span class="muted">('.$this->n($stats['memberships']).' Mitgliedschaften)</span>')
                .$this->row('Einträge', $this->n($stats['items']['active']).' <span class="muted">(+ '.$this->n($stats['items']['archived']).' archiviert)</span>')
                .$this->row('Konten verschlüsselt', $this->n($stats['encryption']['encrypted']).' <span class="muted">('.$this->n($stats['encryption']['migrating']).' in Umstellung, '.$this->n($stats['encryption']['plaintext']).' unverschlüsselt)</span>')
                .'</dl>';

        return '<!doctype html>'
            .'<html lang="de"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta http-equiv="refresh" content="'.self::REFRESH_SECONDS.'">'
            .'<meta name="robots" content="noindex, nofollow"><meta name="color-scheme" content="light dark">'
            .'<title>spiski Status</title><style>'.self::CSS.'</style></head><body>'
            .'<header class="bar"><h1>spiski Status</h1></header><main>'
            .'<section class="hero '.$status['status'].'" aria-live="polite">'
            .'<span class="light" aria-hidden="true"></span>'
            .'<div><p class="state">'.$this->e(self::LABELS[$status['status']]).'</p>'
            .'<p class="muted">Stand '.$this->time($status['checkedAt']).' UTC</p></div></section>'
            .'<div class="grid">'
            .'<section class="card"><h2>API</h2><dl>'.$apiRows.'</dl></section>'
            .'<section class="card"><h2>Betrieb</h2><dl>'.$dbRows.'</dl></section>'
            .'<section class="card wide"><h2>Nutzung</h2>'.$usage.'</section>'
            .'</div>'
            .'<p class="muted note">Aktualisiert sich alle '.self::REFRESH_SECONDS.' Sekunden. '
            .'Maschinenlesbar: <a href="/status.json">/status.json</a></p>'
            .'</main></body></html>';
    }

    private function row(string $label, string $valueHtml): string
    {
        return '<div class="row"><dt>'.$this->e($label).'</dt><dd>'.$valueHtml.'</dd></div>';
    }

    private function badge(string $kind, string $label): string
    {
        return '<span class="badge '.$kind.'">'.$this->e($label).'</span>';
    }

    private function unknown(): string
    {
        return '<span class="muted">unbekannt</span>';
    }

    private function time(string $iso): string
    {
        return '<time datetime="'.$this->e($iso).'">'
            .$this->e((new \DateTimeImmutable($iso))->format('Y-m-d H:i:s'))
            .'</time>';
    }

    private function n(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    private const CSS = <<<'CSS'
        :root{--bg:#f6f8f7;--surface:#fff;--text:#14211f;--muted:#4f625f;--border:#dbe4e1;--accent:#0f766e;--ok:#0f766e;--ok-bg:#e3f4f0;--warn:#a15c07;--warn-bg:#fdf1dc;--down:#b42318;--down-bg:#fde8e6}
        @media (prefers-color-scheme:dark){:root{--bg:#0e1514;--surface:#151f1d;--text:#e6efed;--muted:#9db0ac;--border:#263634;--accent:#2dd4bf;--ok:#2dd4bf;--ok-bg:#12302b;--warn:#fbbf24;--warn-bg:#33270d;--down:#ff8a80;--down-bg:#3a1714}}
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        .bar{padding:12px 16px;background:var(--surface);border-bottom:1px solid var(--border)}
        .bar h1{margin:0;font-size:1.1rem}
        main{max-width:48rem;margin:0 auto;padding:16px}
        .muted{color:var(--muted);margin:0}
        .note{margin-top:16px;font-size:.85rem}
        a{color:var(--accent)}
        a:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
        code{font:.9em ui-monospace,SFMono-Regular,Menlo,monospace}
        .hero{display:flex;align-items:center;gap:16px;padding:16px;margin-bottom:16px;border:1px solid var(--border);border-radius:12px;background:var(--surface)}
        .hero .state{margin:0;font-size:1.25rem;font-weight:650}
        .light{flex:none;width:16px;height:16px;border-radius:50%;background:var(--ok);box-shadow:0 0 0 6px var(--ok-bg)}
        .hero.degraded .light{background:var(--warn);box-shadow:0 0 0 6px var(--warn-bg)}
        .hero.down .light{background:var(--down);box-shadow:0 0 0 6px var(--down-bg)}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(18rem,100%),1fr));gap:16px}
        .card{padding:16px;border:1px solid var(--border);border-radius:12px;background:var(--surface)}
        .card.wide{grid-column:1/-1}
        .card h2{margin:0 0 8px;font-size:.8rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--muted)}
        dl{margin:0}
        .row{display:flex;flex-wrap:wrap;justify-content:space-between;gap:2px 16px;padding:6px 0;border-bottom:1px solid var(--border)}
        .row:last-child{border-bottom:0}
        dt{color:var(--muted)}
        dd{margin:0 0 0 auto;min-width:0;overflow-wrap:anywhere;font-variant-numeric:tabular-nums;text-align:right}
        .badge{padding:1px 10px;font-size:.85rem;font-weight:600;border-radius:999px}
        .badge.ok{color:var(--ok);background:var(--ok-bg)}
        .badge.degraded{color:var(--warn);background:var(--warn-bg)}
        .badge.down{color:var(--down);background:var(--down-bg)}
        CSS;
}
