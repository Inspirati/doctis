-- =============================================================================
-- rewrite-links.lua — Doctis publishing: rewrite internal .md links to .html
--
-- Published pages sit at their repository paths with .md replaced by .html
-- (core/dwg_publish_api.php), so relative links between documents must
-- follow.  Same behaviour as HCRQMS engine/filters/rewrite-links.lua:
--
--   foo.md          → foo.html
--   foo.md#anchor   → foo.html#anchor
--
-- Links with a URI scheme (https:, mailto:, ...) and same-page #anchors are
-- left unchanged.  Doctis runs only this copy, never a filter from a
-- document repository: Lua filters can run programs.
-- =============================================================================

function Link(el)
    local target = el.target

    if target:match("^%a[%w+.-]*:") or target:match("^#") then
        return el
    end

    el.target = target:gsub("%.md#", ".html#")
                      :gsub("%.md$", ".html")
                      :gsub("%.markdown#", ".html#")
                      :gsub("%.markdown$", ".html")

    return el
end
