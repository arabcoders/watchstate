#!/usr/bin/env bash

while true; do
    /opt/bin/console system:worker -v
    sleep 1
done
