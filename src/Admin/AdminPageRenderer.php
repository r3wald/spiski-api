<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * Rendert die Admin-Seite als reines HTML, ohne JavaScript und ohne Template-
 * Engine. Die Eintraege jeder Liste klappen ueber das native <details>-Element
 * auf, dafuer braucht es kein Skript.
 *
 * Alles, was aus der Datenbank stammt (Listennamen, Eintraege und Notizen sind
 * Nutzereingaben!), geht ausschliesslich durch e(). Das Token kommt auf der Seite
 * nie vor: es wird bei jedem Request im Authorization-Header mitgeschickt und
 * serverseitig geprueft.
 */
final class AdminPageRenderer
{
    private const COLUMNS = 6;

    /** Hinweise nach einer Aktion (Query-Parameter `notice`, siehe AdminController). */
    public const NOTICES = [
        'deleted' => 'Der Eintrag wurde gelöscht. Er verschwindet beim nächsten Sync von allen Geräten.',
        'already-deleted' => 'Der Eintrag war bereits archiviert; es wurde nichts geändert.',
        'list-deleted' => 'Die Liste wurde gelöscht. Sie verschwindet beim nächsten Sync von allen Geräten; der Besitzer findet sie im Archiv der App.',
        'list-already-deleted' => 'Die Liste war bereits gelöscht; es wurde nichts geändert.',
    ];

    /**
     * @param array{total: int, ownerCount: int, limit: int, offset: int, lists: list<array<string, mixed>>} $page
     */
    public function render(array $page, AdminPageFilter $filter, ?string $notice = null): string
    {
        $total = $page['total'];
        $limit = $page['limit'];
        $offset = $page['offset'];

        $summary = \sprintf(
            '%d %s von %d %s',
            $total,
            1 === $total ? 'Liste' : 'Listen',
            $page['ownerCount'],
            1 === $page['ownerCount'] ? 'Account' : 'Accounts',
        );
        if ($total > 0) {
            $summary .= \sprintf(' · angezeigt %d–%d', $offset + 1, $offset + \count($page['lists']));
        }

        $return = $this->pageQuery($filter, $limit, $offset);
        $rows = '';
        foreach ($page['lists'] as $list) {
            $rows .= $this->listRows($list, $filter, $return);
        }
        $table = '' === $rows
            ? '<p class="muted">Keine Listen vorhanden.</p>'
            : '<div class="table-wrap"><table>'
                .'<caption class="visually-hidden">Listen aller Accounts, zuletzt geändert zuerst</caption>'
                .'<thead><tr><th scope="col">Liste</th><th scope="col">Account</th>'
                .'<th scope="col">Zugriff</th><th scope="col" class="num">Einträge</th><th scope="col">Geändert (UTC)</th>'
                .'<th scope="col">Status</th></tr></thead><tbody>'.$rows.'</tbody></table></div>';

        $prev = $offset > 0 ? $this->link('Zurück', $filter, $limit, max(0, $offset - $limit)) : '';
        $next = $offset + $limit < $total ? $this->link('Weiter', $filter, $limit, $offset + $limit) : '';
        $pager = '' === $prev.$next ? '' : '<nav class="pager" aria-label="Seiten">'.$prev.$next.'</nav>';

        $banner = null !== $notice && isset(self::NOTICES[$notice])
            ? '<p class="notice" role="status">'.$this->e(self::NOTICES[$notice]).'</p>'
            : '';

        return $this->shell(
            'spiski Admin',
            $banner
            .$this->form($filter)
            .'<p class="muted" aria-live="polite">'.$this->e($summary).'</p>'
            .$table.$pager
            .'<p class="muted note">Das Account-Kürzel ist ein gekürzter Hash der Account-UUID. Die UUID selbst ist das '
            .'Zugangs-Secret der Nutzer und wird hier nie angezeigt. Listennamen, Einträge und Notizen sind Nutzerinhalt.</p>',
        );
    }

    /**
     * Bestaetigungsseite vor dem Loeschen eines Eintrags. Ohne JavaScript: Erst der
     * Button schickt den POST; das Token darin beweist, dass er von dieser Seite kommt
     * (AdminConfirmation).
     *
     * @param array{item: array<string, mixed>, list: array<string, mixed>} $data
     * @param string                                                        $return Query-String der Admin-Seite, auf die es danach zurueckgeht
     */
    public function renderDeleteConfirmation(array $data, string $return, string $token): string
    {
        $item = $data['item'];
        $list = $data['list'];
        $back = '/admin'.('' === $return ? '' : '?'.$return);
        $users = (int) $list['userCount'];

        $facts = '<dl class="facts">'
            .'<dt>Eintrag</dt><dd>'.$this->label($item, $this->itemText($item)).$this->itemTags($item).'</dd>'
            .'<dt>Liste</dt><dd><span class="name">'.$this->dot((int) $list['color']).$this->label($list).'</span>'
            .(null !== $list['deletedAt'] ? ' <span class="tag danger">Liste gelöscht</span>' : '').'</dd>'
            .'<dt>Account</dt><dd><code>'.$this->e((string) $list['owner']).'</code></dd>'
            .'<dt>Betroffen</dt><dd>'.(1 === $users
                ? '1 Nutzer (nicht geteilt)'
                : '<strong>'.$users.' Nutzer</strong> – die Liste ist geteilt, der Eintrag verschwindet bei allen').'</dd>'
            .'</dl>';

        if (null !== $item['deletedAt']) {
            return $this->shell(
                'Eintrag löschen – spiski Admin',
                '<section class="confirm"><h2>Eintrag ist bereits archiviert</h2>'.$facts
                .'<p class="muted">Archiviert am '.$this->e($this->display((string) $item['deletedAt'])).' (UTC). Es gibt nichts mehr zu löschen.</p>'
                .'<p><a class="button-link" href="'.$this->e($back).'">Zurück zur Übersicht</a></p></section>',
            );
        }

        $action = '/admin/items/'.rawurlencode((string) $item['id']).'/delete'.('' === $return ? '' : '?'.$return);

        return $this->shell(
            'Eintrag löschen – spiski Admin',
            '<section class="confirm"><h2>Diesen Eintrag wirklich löschen?</h2>'.$facts
            .'<p>Der Eintrag wird auf allen Geräten '.(1 === $users ? 'des Nutzers' : 'aller '.$users.' Nutzer').' entfernt, '
            .'sobald sie synchronisieren – so, als hätte ihn jemand in der App gelöscht. Auf dem Server bleibt er als '
            .'archivierter Eintrag erhalten.</p>'
            .'<form method="post" action="'.$this->e($action).'" class="actions">'
            .'<input type="hidden" name="confirm" value="'.$this->e($token).'">'
            .'<button type="submit" class="danger">Ja, Eintrag löschen</button>'
            .'<a class="button-link" href="'.$this->e($back).'">Abbrechen</a>'
            .'</form></section>',
        );
    }

    /**
     * Bestaetigungsseite vor dem Loeschen einer ganzen Liste, nach demselben Muster wie
     * bei einem Eintrag.
     *
     * @param array<string, mixed> $list
     * @param string               $return Query-String der Admin-Seite, auf die es danach zurueckgeht
     */
    public function renderListDeleteConfirmation(array $list, string $return, string $token): string
    {
        $back = '/admin'.('' === $return ? '' : '?'.$return);
        $users = (int) $list['userCount'];
        $members = (int) $list['memberCount'];
        $active = (int) $list['activeItemCount'];
        $archived = (int) $list['itemCount'] - $active;

        $facts = '<dl class="facts">'
            .'<dt>Liste</dt><dd><span class="name">'.$this->dot((int) $list['color']).$this->label($list).'</span></dd>'
            .'<dt>Account</dt><dd><code>'.$this->e((string) $list['owner']).'</code></dd>'
            .'<dt>Einträge</dt><dd>'.$active.' aktiv'.($archived > 0 ? ' <span class="muted-inline">(+ '.$archived.' archiviert)</span>' : '').'</dd>'
            .'<dt>Geändert</dt><dd>'.$this->e($this->display((string) $list['updatedAt'])).' UTC</dd>'
            .'<dt>Betroffen</dt><dd>'.(0 === $members
                ? '1 Nutzer (nicht geteilt)'
                : '<strong>'.$users.' Nutzer</strong> – Besitzer + '.$members.(1 === $members ? ' Mitglied' : ' Mitglieder').', die Liste verschwindet bei allen').'</dd>'
            .'</dl>';

        if (null !== $list['deletedAt']) {
            return $this->shell(
                'Liste löschen – spiski Admin',
                '<section class="confirm"><h2>Liste ist bereits gelöscht</h2>'.$facts
                .'<p class="muted">Gelöscht am '.$this->e($this->display((string) $list['deletedAt'])).' (UTC). Es gibt nichts mehr zu löschen.</p>'
                .'<p><a class="button-link" href="'.$this->e($back).'">Zurück zur Übersicht</a></p></section>',
            );
        }

        $action = '/admin/lists/'.rawurlencode((string) $list['id']).'/delete'.('' === $return ? '' : '?'.$return);

        return $this->shell(
            'Liste löschen – spiski Admin',
            '<section class="confirm"><h2>Diese Liste wirklich löschen?</h2>'.$facts
            .'<p>Die Liste verschwindet samt '.(1 === $active ? 'ihrem Eintrag' : 'ihren '.$active.' Einträgen').' auf allen Geräten '
            .(1 === $users ? 'des Nutzers' : 'aller '.$users.' Nutzer').', sobald sie synchronisieren – so, als hätte der Besitzer sie in der App gelöscht. '
            .'Der Besitzer findet sie danach im Archiv der App und kann sie dort wiederherstellen.'
            .(0 === $members ? '' : ' Das Teilen bleibt bestehen; nach einer Wiederherstellung sehen die Mitglieder sie wieder.')
            .'</p>'
            .'<form method="post" action="'.$this->e($action).'" class="actions">'
            .'<input type="hidden" name="confirm" value="'.$this->e($token).'">'
            .'<button type="submit" class="danger">Ja, Liste löschen</button>'
            .'<a class="button-link" href="'.$this->e($back).'">Abbrechen</a>'
            .'</form></section>',
        );
    }

    /**
     * @param array<string, mixed> $list
     */
    private function deleteListLink(array $list, string $return): string
    {
        $name = true === $list['encrypted'] ? 'verschlüsselte Liste' : (string) $list['name'];

        return '<a class="delete list-delete" href="/admin/lists/'.rawurlencode((string) $list['id']).'/delete?'.$this->e($return).'">'
            .'Löschen<span class="visually-hidden">: '.$this->e($name).'</span></a>';
    }

    /**
     * Query-String der Admin-Seite mit Filter und Position, damit Blaettern, Loeschen
     * und Zurueck auf derselben Ansicht landen.
     */
    public function pageQuery(AdminPageFilter $filter, int $limit, int $offset): string
    {
        $params = ['limit' => $limit, 'offset' => $offset];
        if ('' !== $filter->query) {
            $params['q'] = $filter->query;
        }
        if ($filter->hideDeletedLists) {
            $params['hideDeleted'] = 1;
        }
        if ($filter->showArchivedItems) {
            $params['archivedItems'] = 1;
        }
        if ($filter->expandItems) {
            $params['open'] = 1;
        }

        return http_build_query($params);
    }

    private function shell(string $title, string $body): string
    {
        return '<!doctype html>'
            .'<html lang="de"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex, nofollow"><meta name="color-scheme" content="light dark">'
            .'<title>'.$this->e($title).'</title><style>'.self::CSS.'</style></head><body>'
            .'<header class="bar"><h1><a href="/admin">spiski Admin</a></h1></header><main>'
            .$body
            .'</main></body></html>';
    }

    private function form(AdminPageFilter $filter): string
    {
        return '<form method="get" action="/admin" class="toolbar">'
            .'<label class="grow"><span class="visually-hidden">Filtern</span>'
            .'<input type="search" name="q" value="'.$this->e($filter->query).'" maxlength="'.AdminPageLimits::MAX_QUERY_LENGTH.'" '
            .'placeholder="Nach Listenname oder Account-Kürzel filtern" autocomplete="off"></label>'
            .$this->checkbox('hideDeleted', 'Gelöschte Listen ausblenden', $filter->hideDeletedLists)
            .$this->checkbox('archivedItems', 'Archivierte Einträge anzeigen', $filter->showArchivedItems)
            .$this->checkbox('open', 'Einträge aufgeklappt', $filter->expandItems)
            .'<button type="submit">Anwenden</button></form>';
    }

    private function checkbox(string $name, string $label, bool $checked): string
    {
        return '<label class="check"><input type="checkbox" name="'.$name.'" value="1"'.($checked ? ' checked' : '').'> '.$this->e($label).'</label>';
    }

    /**
     * Zwei Tabellenzeilen: die Liste und, darunter ueber alle Spalten, ihre Eintraege.
     *
     * @param array<string, mixed> $list
     */
    private function listRows(array $list, AdminPageFilter $filter, string $return): string
    {
        $itemsRow = $this->itemsRow($list, $filter, $return);
        $deleted = null !== $list['deletedAt'];
        $classes = trim(($deleted ? 'deleted' : '').('' !== $itemsRow ? ' has-items' : ''));
        $active = (int) $list['activeItemCount'];
        $total = (int) $list['itemCount'];

        return '<tr'.('' === $classes ? '' : ' class="'.$classes.'"').'>'
            .'<td><div class="name">'.$this->dot((int) $list['color']).$this->label($list).'</div></td>'
            .'<td><code>'.$this->e((string) $list['owner']).'</code>'.$this->encryptionState((string) $list['encryptionState']).'</td>'
            .'<td>'.$this->access($list).'</td>'
            .'<td class="num">'.$active.' / '.$total.'</td>'
            .'<td><time datetime="'.$this->e((string) $list['updatedAt']).'">'.$this->e($this->display((string) $list['updatedAt'])).'</time></td>'
            .'<td class="status'.($deleted ? ' deleted' : '').'">'.($deleted ? 'gelöscht' : 'aktiv'.$this->deleteListLink($list, $return)).'</td>'
            .'</tr>'.$itemsRow;
    }

    /**
     * Name einer Liste oder eines Eintrags; verschluesselte kann der Server nicht lesen.
     *
     * @param array<string, mixed> $row
     */
    private function label(array $row, string $plain = ''): string
    {
        if (true === $row['encrypted']) {
            return '<span class="label encrypted">🔒 verschlüsselt</span>';
        }

        return '<span class="label">'.$this->e('' === $plain ? (string) $row['name'] : $plain).'</span>';
    }

    /** Zustand des Kontos bei der Umstellung auf Verschluesselung (CLAUDE.md 14). */
    private function encryptionState(string $state): string
    {
        return match ($state) {
            'encrypted' => '<span class="sub">verschlüsselt</span>',
            'migrating' => '<span class="sub"><span class="tag">wird umgestellt</span></span>',
            default => '<span class="sub">unverschlüsselt</span>',
        };
    }

    /**
     * Zugriff: nicht geteilt, Code aktiv ohne Mitglied oder geteilt mit der Zahl der
     * Konten, die die Liste sehen duerfen (Besitzer + Mitglieder).
     *
     * @param array<string, mixed> $list
     */
    private function access(array $list): string
    {
        $members = (int) $list['memberCount'];
        $codeActive = true === $list['shareCodeActive'];

        if ($members > 0) {
            return '<span class="tag shared">geteilt</span> <strong>'.(int) $list['userCount'].' Nutzer</strong>'
                .'<span class="sub">Besitzer + '.$members.(1 === $members ? ' Mitglied' : ' Mitglieder').($codeActive ? ' · Code aktiv' : '').'</span>';
        }

        return $codeActive
            ? '<span class="muted-inline">Code aktiv, noch niemand beigetreten</span>'
            : '<span class="muted-inline">nicht geteilt</span>';
    }

    /**
     * @param array<string, mixed> $list
     */
    private function itemsRow(array $list, AdminPageFilter $filter, string $return): string
    {
        $total = (int) $list['itemCount'];
        if (0 === $total) {
            return '';
        }

        $archived = $total - (int) $list['activeItemCount'];
        $hint = !$filter->showArchivedItems && $archived > 0
            ? \sprintf(' · %d archiviert, ausgeblendet', $archived)
            : '';

        /** @var list<array<string, mixed>> $items */
        $items = $list['items'] ?? [];
        if ([] === $items) {
            return '<tr class="items"><td colspan="'.self::COLUMNS.'"><p class="muted">Keine aktiven Einträge'.$this->e($hint).'</p></td></tr>';
        }

        $count = \count($items);
        $summary = \sprintf('%d %s%s', $count, 1 === $count ? 'Eintrag' : 'Einträge', $hint);

        $lis = '';
        foreach ($items as $item) {
            $lis .= $this->item($item, $return);
        }

        return '<tr class="items"><td colspan="'.self::COLUMNS.'"><details'.($filter->expandItems ? ' open' : '').'>'
            .'<summary>'.$this->e($summary).'</summary><ul class="items">'.$lis.'</ul></details></td></tr>';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function item(array $item, string $return): string
    {
        $archived = null !== $item['deletedAt'];
        $checked = true === $item['isChecked'];
        $text = $this->itemText($item);

        $note = '' === (string) ($item['note'] ?? '')
            ? ''
            : '<span class="note-text">Notiz: '.$this->e((string) $item['note']).'</span>';

        // Archivierte Eintraege sind schon geloescht; dort gibt es nichts mehr zu tun.
        $delete = $archived
            ? ''
            : '<a class="delete" href="/admin/items/'.rawurlencode((string) $item['id']).'/delete?'.$this->e($return).'">'
                .'Löschen<span class="visually-hidden">: '.$this->e(true === $item['encrypted'] ? 'verschlüsselter Eintrag' : $text).'</span></a>';

        return '<li class="item'.($archived ? ' archived' : '').($checked ? ' done' : '').'">'
            .'<span class="box" aria-hidden="true">'.($checked ? '☑' : '☐').'</span>'
            .'<span class="visually-hidden">'.($checked ? 'erledigt: ' : 'offen: ').'</span>'
            .$this->label($item, $text)
            .$this->itemTags($item).$delete.$note
            .'</li>';
    }

    /**
     * Menge, Einheit und Name wie in der App, z. B. `0,5 kg Mehl`.
     *
     * @param array<string, mixed> $item
     */
    private function itemText(array $item): string
    {
        return implode(' ', array_filter(
            [$this->quantity($item['quantity']), (string) ($item['unit'] ?? ''), (string) ($item['name'] ?? '')],
            static fn (string $part): bool => '' !== $part,
        ));
    }

    /**
     * @param array<string, mixed> $item
     */
    private function itemTags(array $item): string
    {
        $tags = '';
        if (true === $item['isChecked']) {
            $tags .= '<span class="tag">erledigt</span>';
        }
        if (null !== $item['deletedAt']) {
            $tags .= '<span class="tag danger">archiviert '.$this->e($this->display((string) $item['deletedAt'])).'</span>';
        }
        if (null !== $item['movedAt']) {
            $tags .= '<span class="tag">verschoben</span>';
        }

        return $tags;
    }

    /**
     * Wie die App: ganze Zahlen ohne Nachkommastellen, sonst mit Komma (`0,5`).
     */
    private function quantity(mixed $value): string
    {
        if (!\is_float($value) && !\is_int($value)) {
            return '';
        }

        $number = (float) $value;
        if (floor($number) === $number) {
            return (string) (int) $number;
        }

        return rtrim(rtrim(number_format($number, 3, ',', ''), '0'), ',');
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

    private function link(string $label, AdminPageFilter $filter, int $limit, int $offset): string
    {
        return '<a href="/admin?'.$this->e($this->pageQuery($filter, $limit, $offset)).'">'.$this->e($label).'</a>';
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
        :root{--bg:#f6f8f7;--surface:#fff;--text:#14211f;--muted:#4f625f;--border:#dbe4e1;--accent:#0f766e;--accent-text:#fff;--danger:#b42318;--danger-text:#fff;--row-hover:#f0f5f3;--tag:#e7efec}
        @media (prefers-color-scheme:dark){:root{--bg:#0e1514;--surface:#151f1d;--text:#e6efed;--muted:#9db0ac;--border:#263634;--accent:#2dd4bf;--accent-text:#052e2b;--danger:#ff8a80;--danger-text:#2b0a07;--row-hover:#1a2724;--tag:#223330}}
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        .visually-hidden{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
        .bar{padding:12px 16px;background:var(--surface);border-bottom:1px solid var(--border)}
        .bar h1{margin:0;font-size:1.1rem}
        main{max-width:64rem;margin:0 auto;padding:16px}
        .muted{color:var(--muted);margin:0 0 12px}
        .note{margin-top:16px;font-size:.85rem}
        .toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;margin-bottom:12px}
        .toolbar label{margin:0}.toolbar .grow{flex:1 1 100%}.toolbar .check{display:flex;align-items:center;gap:6px}
        input[type=search]{width:100%;padding:10px 12px;font:inherit;color:var(--text);background:var(--surface);border:1px solid var(--border);border-radius:8px}
        button{padding:10px 16px;font:inherit;font-weight:600;color:var(--accent-text);background:var(--accent);border:1px solid var(--accent);border-radius:8px;cursor:pointer}
        input:focus-visible,button:focus-visible,a:focus-visible,summary:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
        .table-wrap{overflow-x:auto;background:var(--surface);border:1px solid var(--border);border-radius:12px}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px 12px;text-align:left;white-space:nowrap}
        th{font-size:.8rem;color:var(--muted);border-bottom:1px solid var(--border)}
        td{border-bottom:1px solid var(--border)}
        tbody tr:last-child td{border-bottom:0}
        tbody tr:not(.items):hover{background:var(--row-hover)}
        tr.has-items>td{border-bottom:0}
        tr.items>td{padding:0 12px 12px 36px;white-space:normal}
        tr.items p{margin:0}
        .num{text-align:right}
        code{font:.85em ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--muted)}
        .name{display:flex;align-items:center;gap:10px;white-space:normal}
        .dot{flex:none;width:14px;height:14px}
        tr.deleted .label{text-decoration:line-through;color:var(--muted)}
        .status.deleted{color:var(--danger)}
        details>summary{cursor:pointer;color:var(--muted);font-size:.9rem}
        ul.items{list-style:none;margin:8px 0 0;padding:0}
        li.item{display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 10px;padding:4px 0}
        li.item .box{color:var(--muted)}
        li.item.done .label{color:var(--muted)}
        li.item.archived .label{text-decoration:line-through;color:var(--muted)}
        .tag{padding:0 8px;font-size:.75rem;color:var(--muted);background:var(--tag);border-radius:999px}
        .tag.danger{color:var(--danger)}
        .tag.shared{color:var(--accent-text);background:var(--accent)}
        .sub{display:block;font-size:.8rem;color:var(--muted)}
        .label.encrypted{color:var(--muted);font-style:italic}
        .muted-inline{color:var(--muted);font-size:.9rem}
        .note-text{flex-basis:100%;padding-left:26px;font-size:.85rem;color:var(--muted)}
        .pager{display:flex;justify-content:center;gap:24px;margin-top:16px}
        .pager a{color:var(--accent);font-weight:600}
        .bar h1 a{color:inherit;text-decoration:none}
        a.delete{margin-left:auto;font-size:.8rem;color:var(--danger)}
        a.delete:hover{text-decoration:underline}
        a.list-delete{display:block;margin:2px 0 0}
        .notice{padding:10px 14px;margin:0 0 12px;background:var(--surface);border:1px solid var(--accent);border-left-width:4px;border-radius:8px}
        .confirm{max-width:40rem;padding:20px;background:var(--surface);border:1px solid var(--border);border-radius:12px}
        .confirm h2{margin:0 0 12px;font-size:1.15rem}
        .facts{display:grid;grid-template-columns:auto 1fr;gap:6px 16px;margin:0 0 16px}
        .facts dt{color:var(--muted)}.facts dd{margin:0;display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 8px}
        .facts .name{display:inline-flex}
        .actions{display:flex;flex-wrap:wrap;align-items:center;gap:12px 20px;margin-top:20px}
        button.danger{color:var(--danger-text);background:var(--danger);border-color:var(--danger)}
        a.button-link{color:var(--accent);font-weight:600}
        CSS;
}
