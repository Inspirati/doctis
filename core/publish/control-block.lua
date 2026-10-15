-- =============================================================================
-- control-block.lua — Doctis publishing: insert the document-control block
--
-- The git store is the source of document content; Doctis is the source of
-- document status.  Publishing combines the two: core/dwg_publish_api.php
-- passes the Doctis values as metadata (doctis-number, doctis-status, ...)
-- with their labels (doctis-label-number, ...), and this filter turns them
-- into a two-column table at the top of the document, after its first
-- heading when the document starts with one.
--
-- The block is built as pandoc elements, not raw markup, so it renders in
-- every output format (HTML and PDF) and pandoc escapes every value.  It is
-- a Div with class "doctis-control" for styling.  Rows with an empty value
-- are left out.  Runs only when doctis-control is set.
-- =============================================================================

local KEYS = {
    'number', 'title', 'revision', 'status', 'classification', 'owner',
    'approved', 'effective', 'review', 'record', 'published',
}

local function text(value)
    if value == nil then
        return ''
    end
    return pandoc.utils.stringify(value)
end

function Pandoc(doc)
    local meta = doc.meta
    if text(meta['doctis-control']) == '' then
        return nil
    end

    local rows = {}
    for _, key in ipairs(KEYS) do
        local value = text(meta['doctis-' .. key])
        local label = text(meta['doctis-label-' .. key])
        if value ~= '' and label ~= '' then
            table.insert(rows, {
                { pandoc.Plain({ pandoc.Strong(pandoc.Inlines(label)) }) },
                { pandoc.Plain(pandoc.Inlines(value)) },
            })
        end
    end
    if #rows == 0 then
        return nil
    end

    local simple = pandoc.SimpleTable(
        {}, { pandoc.AlignLeft, pandoc.AlignLeft }, { 0.3, 0.7 }, {}, rows)
    local block = pandoc.Div({ pandoc.utils.from_simple_table(simple) },
        pandoc.Attr('', { 'doctis-control' }))

    local position = 1
    if #doc.blocks > 0 and doc.blocks[1].t == 'Header' and doc.blocks[1].level == 1 then
        position = 2
    end
    doc.blocks:insert(position, block)
    return doc
end
