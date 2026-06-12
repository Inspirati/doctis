#!/bin/bash

# Dump all files in the current directory
find . -type f \
  -not -path "*/.git/*" \
  -not -name "*.swp" \
  -not -name "*.tmp" \
| while IFS= read -r f; do
    echo "===== $f ====="
    cat "$f"
    echo
done

