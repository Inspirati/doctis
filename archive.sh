#!/bin/bash

name=${PWD##*/}          # to assign to a variable
name=${name:-/}          # to correct for the case where PWD is / (root)
timestamp=$(date +%Y-%m-%d_%H-%M-%S)
hostname=$(hostname)

printf '%q\n' "${name}"  # to print to stdout, quoted for use as shell input
echo $timestamp

tar --exclude="*.tar.gz" \
    --exclude=".Trash-*" \
    -czvf \
    ../${hostname}_${name}_${timestamp}.tar.gz *

