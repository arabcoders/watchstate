# Run the WatchState worker with systemd

WatchState uses one long-running worker command:

- `system:worker` runs scheduled tasks and commands submitted through the web console.

The following system service replaces the included shell runner on a non-container installation. It starts during boot 
and does not require an interactive login. Do not run the shell runner and this service against the same WatchState data directory.

## Check the installation paths

Choose the account that will run WatchState. The examples use the `watchstate` user and group. Replace both values 
if your installation uses another account. Check the PHP executable and WatchState installation as that user:

```bash
sudo -u watchstate /usr/bin/php /absolute/path/to/watchstate/bin/console --version
```

Replace `/usr/bin/php` if `command -v php` reports a different PHP executable. Replace `/absolute/path/to/watchstate` in every 
unit below with the WatchState installation directory. The service user needs read access to the installation and write access 
to the WatchState data and temporary directories.

## Create the service environment file

Create the configuration directory:

```bash
sudo mkdir -p /etc/watchstate
```

Create `/etc/watchstate/systemd.env`:

```ini
WS_DATA_PATH=/absolute/path/to/watchstate-data
WS_TMP_DIR=/absolute/path/to/watchstate-data/tmp
```

Use absolute paths. The worker PID file defaults to `${WS_TMP_DIR}/worker.pid`.

To set a different location, add this variable:

```ini
WS_WORKER_PID_FILE=/absolute/path/to/run/worker.pid
```

The directory containing the PID file must exist and be writable by the service user.

The WatchState HTTP service must resolve the same `WS_DATA_PATH`, `WS_TMP_DIR`, and optional `WS_WORKER_PID_FILE`. 
If the HTTP service uses systemd, add `EnvironmentFile=/etc/watchstate/systemd.env` to its unit. A mismatch 
causes the command API to report that the worker is unavailable.

## Create the worker service

Create `/etc/systemd/system/watchstate-worker.service`:

```ini
[Unit]
Description=WatchState worker
Wants=network-online.target
After=network-online.target

[Service]
Type=simple
User=watchstate
Group=watchstate
WorkingDirectory=/absolute/path/to/watchstate
EnvironmentFile=/etc/watchstate/systemd.env
ExecStart=/usr/bin/php /absolute/path/to/watchstate/bin/console system:worker -v
Restart=always
RestartSec=1

[Install]
WantedBy=multi-user.target
```

Do not add `PIDFile=` to the unit. The command stays in the foreground, so systemd tracks its process directly. 
WatchState's PID file is used by its status and command APIs.

The `-v` flag records console job pickup and completion. Use `-vv` instead when scheduled-task scan activity is also needed for troubleshooting.

## Start the worker

Load the unit and start the service:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now watchstate-worker.service
```

Check its status:

```bash
sudo systemctl status watchstate-worker.service
```

Read its logs:

```bash
sudo journalctl -u watchstate-worker.service
```

Add `-f` to the `journalctl` command to follow new records.

## Restart after an update

Restart the worker after updating WatchState code or dependencies:

```bash
sudo systemctl restart watchstate-worker.service
```

Run `sudo systemctl daemon-reload` first if the unit file changed.

## Stop and remove the worker

Disable and stop the unit:

```bash
sudo systemctl disable --now watchstate-worker.service
```

Remove the unit file, then reload systemd:

```bash
sudo rm /etc/systemd/system/watchstate-worker.service
sudo systemctl daemon-reload
```

## Troubleshooting

- If the worker cannot start PHP, verify the executable in `ExecStart=` with `command -v php`.
- If the worker cannot enter its working directory, replace every `/absolute/path/to/watchstate` placeholder.
- If WatchState cannot create a PID file, create its parent directory and grant the service user write access.
- If the command API returns `503 Service Unavailable`, check `watchstate-worker.service` and confirm that the 
  HTTP process uses the same environment and WatchState configuration.
- If systemd reports that the worker is already running, stop the old runner or process before starting the service.
