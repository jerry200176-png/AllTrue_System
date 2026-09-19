#!/usr/bin/env python3
import errno
import fcntl
import os
import secrets
import stat
import sys
import subprocess

parent_fd = int(sys.argv[1])
basename = sys.argv[2]
action = sys.argv[3]

if action == "validate":
    if len(sys.argv) != 6:
        raise SystemExit("invalid validate argument count")
    configured_path = os.path.realpath(sys.argv[4])
    configured_parent = os.path.dirname(configured_path)
    if (os.path.basename(configured_path) != basename or not basename or basename in {".", ".."}
            or "/" in basename or os.path.isabs(basename)):
        raise RuntimeError("credential basename does not match configured path")
    parent_st = os.fstat(parent_fd)
    if not stat.S_ISDIR(parent_st.st_mode) or parent_st.st_uid != os.getuid() or stat.S_IMODE(parent_st.st_mode) != 0o700:
        raise RuntimeError("inherited parent descriptor is unsafe")
    path_st = os.stat(configured_parent)
    if (path_st.st_dev, path_st.st_ino) != (parent_st.st_dev, parent_st.st_ino):
        raise RuntimeError("inherited parent does not match configured parent")
    lock_st = os.fstat(int(sys.argv[5]))
    if not stat.S_ISREG(lock_st.st_mode) or lock_st.st_uid != os.getuid() or stat.S_IMODE(lock_st.st_mode) != 0o600:
        raise RuntimeError("inherited lock descriptor is unsafe")
    try:
        fcntl.flock(int(sys.argv[5]), fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        raise RuntimeError("inherited lock descriptor is already contested")
    probe = os.open(".staging-db-password.lock", os.O_RDWR | os.O_NOFOLLOW, dir_fd=parent_fd)
    try:
        probe_st = os.fstat(probe)
        if (probe_st.st_dev, probe_st.st_ino) != (lock_st.st_dev, lock_st.st_ino):
            raise RuntimeError("inherited lock descriptor does not match lockfile")
    finally:
        os.close(probe)
    raise SystemExit(0)

if action == "state":
    if len(sys.argv) != 4:
        raise SystemExit("invalid state argument count")
    try:
        fd = os.open(basename, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=parent_fd)
    except OSError as exc:
        if exc.errno == errno.ENOENT:
            print("missing")
            raise SystemExit(0)
        raise
    os.close(fd)
    print("present")
    raise SystemExit(0)

if action == "create":
    if len(sys.argv) != 4:
        raise SystemExit("invalid create argument count")
    fd = os.open(basename, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=parent_fd)
    try:
        os.write(fd, secrets.token_hex(24).encode("ascii") + b"\n")
        os.fsync(fd)
        st = os.fstat(fd)
    finally:
        os.close(fd)
    if not stat.S_ISREG(st.st_mode) or st.st_uid != os.getuid() or stat.S_IMODE(st.st_mode) != 0o600:
        raise RuntimeError("created credential file failed same-fd validation")
    raise SystemExit(0)

def read_password():
    fd = os.open(basename, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=parent_fd)
    try:
        st = os.fstat(fd)
        raw = os.read(fd, 128)
    finally:
        os.close(fd)
    if not stat.S_ISREG(st.st_mode) or st.st_uid != os.getuid() or stat.S_IMODE(st.st_mode) != 0o600:
        raise RuntimeError("credential file ownership/type/mode invalid")
    if raw.count(b"\n") != 1 or not raw.endswith(b"\n"):
        raise RuntimeError("credential file must contain exactly one line")
    password = raw[:-1].decode("ascii")
    if len(password) != 48 or any(c not in "0123456789abcdefABCDEF" for c in password):
        raise RuntimeError("credential file must contain one 48-hex line")
    return password

if action == "root-mysql":
    if len(sys.argv) != 9:
        raise SystemExit("invalid root-mysql argument count")
    mode, db, user, host, operation = sys.argv[4:9]
    if mode not in {"sudo", "password"} or db != "AllTrue_staging" or user != "atr_staging" or host not in {"localhost", "127.0.0.1"} or operation not in {"first", "add-host"}:
        raise SystemExit("invalid root-mysql request")
    password = read_password()
    sql = (
        f"CREATE USER '{user}'@'{host}' IDENTIFIED BY '{password}';\n"
        f"GRANT ALL PRIVILEGES ON `{db}`.* TO '{user}'@'{host}';\nFLUSH PRIVILEGES;\n"
    )
    command = ["sudo", "mysql"] if mode == "sudo" else ["mysql", "-u", "root"]
    env = os.environ.copy()
    if mode != "password":
        env.pop("MYSQL_PWD", None)
    result = subprocess.run(command, input=sql.encode(), env=env, capture_output=True, check=False)
    if result.returncode:
        raise SystemExit("root mysql failed")
    raise SystemExit(0)

if action == "mysql":
    allowed = {
        ("--protocol=SOCKET", "-u", "atr_staging", "-e", "SELECT 1 AS ok"),
        ("--protocol=TCP", "-h", "127.0.0.1", "-u", "atr_staging", "-e", "SELECT 1 AS ok"),
        ("--protocol=TCP", "-h", "127.0.0.1", "-u", "atr_staging", "AllTrue_staging", "-e", "SELECT 1 AS ok"),
    }
    if tuple(sys.argv[4:]) not in allowed:
        raise SystemExit("invalid staging mysql request")
    fd = os.open(basename, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=parent_fd)
    try:
        st = os.fstat(fd)
        raw = os.read(fd, 128)
    finally:
        os.close(fd)
    if not stat.S_ISREG(st.st_mode) or st.st_uid != os.getuid() or stat.S_IMODE(st.st_mode) != 0o600:
        raise RuntimeError("credential file ownership/type/mode invalid")
    if raw.count(b"\n") != 1 or not raw.endswith(b"\n"):
        raise RuntimeError("credential file must contain exactly one line")
    password = raw[:-1].decode("ascii")
    if len(password) != 48 or any(c not in "0123456789abcdefABCDEF" for c in password):
        raise RuntimeError("credential file must contain one 48-hex line")
    read_fd, write_fd = os.pipe()
    os.write(write_fd, b"[client]\npassword=" + password.encode("ascii") + b"\n")
    os.close(write_fd)
    os.set_inheritable(read_fd, True)
    env = {k: v for k, v in os.environ.items() if k != "MYSQL_PWD"}
    try:
        result = subprocess.run(
            ["mysql", f"--defaults-extra-file=/proc/self/fd/{read_fd}", *sys.argv[4:]],
            env=env, pass_fds=(read_fd,), capture_output=True, check=False,
        )
        if result.returncode:
            raise SystemExit("staging mysql failed")
        raise SystemExit(0)
    finally:
        os.close(read_fd)

raise SystemExit("unsupported credential helper action")
