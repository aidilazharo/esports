<?php
/**
 * Mobile-only icon navigation shared by public pages and authenticated portals.
 * Menus are generated from existing, role-appropriate routes; no database writes.
 */

if (!function_exists('mobileNavigationBar')) {
    /**
     * @param array<int,array{0:string,1:string,2:string,3?:bool}> $items
     */
    function mobileNavigationBar(array $items, string $label, string $location, string $extraHtml = ''): string
    {
        if (!$items && $extraHtml === '') {
            return '';
        }

        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $bottom = $location === 'bottom';
        $class = $bottom ? 'mobile-bottom-nav' : 'mobile-top-nav';
        $html = '<nav class="'.$class.'" aria-label="'.$escape($label).'">'
            . '<div class="mobile-nav-scroller">';

        foreach ($items as $item) {
            $name = trim((string)($item[0] ?? ''));
            $href = (string)($item[1] ?? '');
            $icon = (string)($item[2] ?? 'bi-circle');
            $active = !empty($item[3]);

            if ($name === '' || $href === '') {
                continue;
            }

            // Icon class is restricted to Bootstrap Icons syntax.
            if (!preg_match('/^bi-[a-z0-9-]+$/D', $icon)) {
                $icon = 'bi-circle';
            }

            $html .= '<a class="mobile-nav-link'.($active ? ' active' : '').'"'
                .' href="'.$escape($href).'"'
                .' title="'.$escape($name).'"'
                .' aria-label="'.$escape($name).'"'
                .($active ? ' aria-current="page"' : '')
                .'><i class="bi '.$escape($icon).'" aria-hidden="true"></i>'
                .'<span class="visually-hidden">'.$escape($name).'</span></a>';
        }

        $html .= $extraHtml;
        $html .= '</div></nav>';
        return $html;
    }
}

if (!function_exists('mobileNavigationRoleItems')) {
    /**
     * Split existing role menu without exposing additional links/privileges.
     * @return array{0:array,1:array}
     */
    function mobileNavigationRoleItems(array $menu, array $primaryKeys, string $activeKey): array
    {
        $top = [];
        $bottom = [];

        foreach ($primaryKeys as $key) {
            if (!isset($menu[$key])) {
                continue;
            }
            $item = $menu[$key];
            $top[] = [
                (string)($item[0] ?? $key),
                (string)($item[1] ?? ''),
                (string)($item[2] ?? 'bi-circle'),
                $key === $activeKey,
            ];
        }

        foreach ($menu as $key => $item) {
            if (in_array($key, $primaryKeys, true)) {
                continue;
            }
            $bottom[] = [
                (string)($item[0] ?? $key),
                (string)($item[1] ?? ''),
                (string)($item[2] ?? 'bi-circle'),
                $key === $activeKey,
            ];
        }

        return [$top, $bottom];
    }
}
