#!/usr/bin/env bash

while true; do
    /opt/bin/console system:scheduler --pid-file /tmp/ws-job-runner.pid
    sleep 60
done
