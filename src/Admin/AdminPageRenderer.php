<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * Rendert die Admin-Seite als reines HTML, ohne JavaScript und ohne Template-
 * Engine.
 *
 * Alles, was aus der Datenbank stammt (Listennamen sind Nutzereingaben!), geht
 * ausschliesslich durch e(). Das Token kommt auf der Seite nie vor: es wird bei
 * jedem Request im Authorization-Header mitgeschickt und serverseitig geprueft.
 */
final class AdminPageRenderer
{
    /**
     * @param array{total: int, ownerCount: int, limit: int, offset: int, lists: list<array<string, mixed>>} $page
     */
    public function render(array $page, string $query, bool $hideDeleted): string
    {
        $total = $page['total'];
        $limit = $page['limit'];
        $offset = $page['offset'];
        $shownFrom = 0 === $total ? 0 : $offset + 1;
        $shownTo = $offset + \count($page['lists']);

        $summary = \sprintf(
            '%d %s von %d %s',
            $total,
            1 === $total ? 'Liste' : 'Listen',
            $page['ownerCount'],
            1 === $page['ownerCount'] ? 'Account' : 'Accounts',
        );
        if ($total > 0) {
            $summary .= \sprintf(' · angezeigt %d–%d', $shownFrom, $shownTo);
        }

        $rows = '';
        foreach ($page['lists'] as $list) {
            $rows .= $this->row($list);
        }
        $table = '' === $rows
            ? '<p class="muted">Keine Listen vorhanden.</p>'
            : '<div class="table-wrap"><table>'
                .'<caption class="visually-hidden">Listen aller Accounts, zuletzt geändert zuerst</caption>'
                .'<thead><tr><th scope="col">Liste</th><th scope="col">Account</th>'
                .'<th scope="col" class="num">Artikel</th><th scope="col">Geändert (UTC)</th>'
                .'<th scope="col">Status</th></tr></thead><tbody>'.$rows.'</tbody></table></div>';

        $prev = $offset > 0
            ? $this->link('Zurück', $query, $hideDeleted, $limit, max(0, $offset - $limit))
            : '';
        $next = $offset + $limit < $total
            ? $this->link('Weiter', $query, $hideDeleted, $limit, $offset + $limit)
            : '';
        $pager = '' === $prev.$next ? '' : '<nav class="pager" aria-label="Seiten">'.$prev.$next.'</nav>';

        return '<!doctype html>'
            .'<html lang="de"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex, nofollow"><meta name="color-scheme" content="light dark">'
            .'<title>spiski Admin</title><style>'.self::CSS.'</style></head><body>'
            .'<header class="bar"><h1>spiski Admin</h1></header><main>'
            .'<form method="get" action="/admin" class="toolbar">'
            .'<label class="grow"><span class="visually-hidden">Filtern</span>'
            .'<input type="search" name="q" value="'.$this->e($query).'" maxlength="'.AdminPageLimits::MAX_QUERY_LENGTH.'" '
            .'placeholder="Nach Name oder Account-Kürzel filtern" autocomplete="off"></label>'
            .'<label class="check"><input type="checkbox" name="hideDeleted" value="1"'.($hideDeleted ? ' checked' : '').'> Gelöschte ausblenden</label>'
            .'<button type="submit">Anwenden</button></form>'
            .'<p class="muted" aria-live="polite">'.$this->e($summary).'</p>'
            .$table.$pager
            .'<p class="muted note">Das Account-Kürzel ist ein gekürzter Hash der Account-UUID. Die UUID selbst ist das '
            .'Zugangs-Secret der Nutzer und wird hier nie angezeigt.</p>'
            .'</main></body></html>';
    }

    /**
     * @param array<string, mixed> $list
     */
    private function row(array $list): string
    {
        $deleted = null !== $list['deletedAt'];

        return '<tr'.($deleted ? ' class="deleted"' : '').'>'
            .'<td><div class="name">'.$this->dot((int) $list['color']).'<span class="label">'.$this->e((string) $list['name']).'</span></div></td>'
            .'<td><code>'.$this->e((string) $list['owner']).'</code></td>'
            .'<td class="num">'.(int) $list['activeItemCount'].' / '.(int) $list['itemCount'].'</td>'
            .'<td><time datetime="'.$this->e((string) $list['updatedAt']).'">'.$this->e($this->display((string) $list['updatedAt'])).'</time></td>'
            .'<td class="status'.($deleted ? ' deleted' : '').'">'.($deleted ? 'gelöscht' : 'aktiv').'</td>'
            .'</tr>';
    }

    /**
     * Farbpunkt als SVG: `fill` ist ein Darstellungsattribut und braucht keine
     * Ausnahme in der Content-Security-Policy. Der Wert entsteht aus einer Zahl,
     * nicht aus Nutzertext.
     */
    private function dot(int $argb): string
    {
        return \sprintf(
            '<svg class="dot" viewBox="0 0 10 10" aria-hidden="true"><circle cx="5" cy="5" r="4.5" fill="#%06x" stroke="currentColor" stroke-opacity=".3"/></svg>',
            $argb & 0xFFFFFF,
        );
    }

    private function link(string $label, string $query, bool $hideDeleted, int $limit, int $offset): string
    {
        $params = ['limit' => $limit, 'offset' => $offset];
        if ('' !== $query) {
            $params['q'] = $query;
        }
        if ($hideDeleted) {
            $params['hideDeleted'] = 1;
        }

        return '<a href="/admin?'.$this->e(http_build_query($params)).'">'.$this->e($label).'</a>';
    }

    private function display(string $isoTimestamp): string
    {
        return (new \DateTimeImmutable($isoTimestamp))->format('Y-m-d H:i:s');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    private const CSS = <<<'CSS'
        :root{--bg:#f6f8f7;--surface:#fff;--text:#14211f;--muted:#4f625f;--border:#dbe4e1;--accent:#0f766e;--accent-text:#fff;--danger:#b42318;--row-hover:#f0f5f3}
        @media (prefers-color-scheme:dark){:root{--bg:#0e1514;--surface:#151f1d;--text:#e6efed;--muted:#9db0ac;--border:#263634;--accent:#2dd4bf;--accent-text:#052e2b;--danger:#ff8a80;--row-hover:#1a2724}}
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        .visually-hidden{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
        .bar{padding:12px 16px;background:var(--surface);border-bottom:1px solid var(--border)}
        .bar h1{margin:0;font-size:1.1rem}
        main{max-width:64rem;margin:0 auto;padding:16px}
        .muted{color:var(--muted);margin:0 0 12px}
        .note{margin-top:16px;font-size:.85rem}
        .toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-bottom:12px}
        .toolbar label{margin:0}.toolbar .grow{flex:1 1 14rem}.toolbar .check{display:flex;align-items:center;gap:6px}
        input[type=search]{width:100%;padding:10px 12px;font:inherit;color:var(--text);background:var(--surface);border:1px solid var(--border);border-radius:8px}
        button{padding:10px 16px;font:inherit;font-weight:600;color:var(--accent-text);background:var(--accent);border:1px solid var(--accent);border-radius:8px;cursor:pointer}
        input:focus-visible,button:focus-visible,a:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
        .table-wrap{overflow-x:auto;background:var(--surface);border:1px solid var(--border);border-radius:12px}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px 12px;text-align:left;white-space:nowrap}
        th{font-size:.8rem;color:var(--muted);border-bottom:1px solid var(--border)}
        td{border-bottom:1px solid var(--border)}
        tbody tr:last-child td{border-bottom:0}tbody tr:hover{background:var(--row-hover)}
        .num{text-align:right}
        code{font:.85em ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--muted)}
        .name{display:flex;align-items:center;gap:10px;white-space:normal}
        .dot{flex:none;width:14px;height:14px}
        tr.deleted .label{text-decoration:line-through;color:var(--muted)}
        .status.deleted{color:var(--danger)}
        .pager{display:flex;justify-content:center;gap:24px;margin-top:16px}
        .pager a{color:var(--accent);font-weight:600}
        CSS;
}
