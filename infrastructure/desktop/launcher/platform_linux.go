package main

import (
	"errors"
	"io/fs"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
)

const phpExecutable = "php"

func defaultDataDirectory() (string, error) {
	base := os.Getenv("XDG_DATA_HOME")
	if base == "" {
		home, err := os.UserHomeDir()
		if err != nil {
			return "", err
		}
		base = filepath.Join(home, ".local", "share")
	}
	return filepath.Join(base, "canabicultarr"), nil
}

func privateDataDirectory(info fs.FileInfo) bool {
	return info.IsDir() && info.Mode().Perm()&0077 == 0 && info.Sys().(*syscall.Stat_t).Uid == uint32(os.Geteuid())
}

func lockLauncher(data string) (func(), bool, error) {
	lock, err := os.OpenFile(filepath.Join(data, "launcher.lock"), os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		return nil, false, err
	}
	if err := syscall.Flock(int(lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil && !errors.Is(err, syscall.EWOULDBLOCK) {
		lock.Close()
		return nil, false, err
	} else {
		return func() { _ = lock.Close() }, err == nil, nil
	}
}

func openDefaultBrowser(address string) error {
	command := exec.Command("xdg-open", address)
	if _, err := exec.LookPath("xdg-open"); err != nil {
		command = exec.Command("gio", "open", address)
	}
	if err := command.Start(); err != nil {
		return errors.New("the desktop could not open the default browser")
	}
	go command.Wait()
	return nil
}

func configureRuntimeCommand(cmd *exec.Cmd, runtime string) {
	cmd.Env = append(cmd.Env, "PATH=/usr/bin:/bin", "LD_LIBRARY_PATH="+filepath.Join(runtime, "lib"))
}

func requestRuntimeStop(process *os.Process, _, _ string) {
	_ = process.Signal(syscall.SIGTERM)
}

func reportLauncherError(err error) { log.Print(err) }

// Unix runtime children manage their own process groups and termination.
func containRuntime(_ *os.Process) (func(), error) { return func() {}, nil }
