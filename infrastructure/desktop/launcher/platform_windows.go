package main

import (
	"errors"
	"fmt"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"unsafe"
)

const phpExecutable = "php.exe"

var (
	kernel      = syscall.NewLazyDLL("kernel32.dll")
	createMutex = kernel.NewProc("CreateMutexW")
	closeHandle = kernel.NewProc("CloseHandle")
	shell       = syscall.NewLazyDLL("shell32.dll").NewProc("ShellExecuteW")
	messageBox  = syscall.NewLazyDLL("user32.dll").NewProc("MessageBoxW")
)

func defaultDataDirectory() (string, error) {
	base := os.Getenv("LOCALAPPDATA")
	if base == "" {
		return "", errors.New("Windows LocalAppData is unavailable")
	}
	return filepath.Join(base, "Canabicultarr", "Data"), nil
}

func privateDataDirectory(info fs.FileInfo) bool { return info.IsDir() }

func lockLauncher(_ string) (func(), bool, error) {
	// The named object stays alive until the launcher closes its handle.
	// The per-user installer and uninstaller use this same name.
	name, _ := syscall.UTF16PtrFromString(`Local\Canabicultarr-Desktop`)
	handle, _, lastError := createMutex.Call(0, 0, uintptr(unsafe.Pointer(name)))
	if handle == 0 {
		return nil, false, lastError
	}
	return func() { _, _, _ = closeHandle.Call(handle) }, !errors.Is(lastError, syscall.ERROR_ALREADY_EXISTS), nil
}

func openDefaultBrowser(address string) error {
	target, err := syscall.UTF16PtrFromString(address)
	if err != nil {
		return err
	}
	result, _, _ := shell.Call(0, 0, uintptr(unsafe.Pointer(target)), 0, 0, 1)
	if result <= 32 {
		return errors.New("Windows could not open the default browser")
	}
	return nil
}

func configureRuntimeCommand(cmd *exec.Cmd, runtime string) {
	for _, name := range []string{"SystemRoot", "WINDIR", "COMSPEC", "TEMP", "TMP", "USERPROFILE", "LOCALAPPDATA"} {
		if value := os.Getenv(name); value != "" {
			cmd.Env = append(cmd.Env, name+"="+value)
		}
	}
	cmd.Env = append(cmd.Env, "PATH="+runtime+";"+filepath.Join(os.Getenv("SystemRoot"), "System32"))
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: 0x08000000}
}

func requestRuntimeStop(_ *os.Process, data, runID string) {
	_ = os.WriteFile(filepath.Join(data, "stop-"+runID), nil, 0600)
}

func reportLauncherError(err error) {
	fmt.Fprintln(os.Stderr, err)
	if os.Getenv("CANABICULTARR_DESKTOP_NO_BROWSER") == "1" {
		return
	}
	title, _ := syscall.UTF16PtrFromString("Canabicultarr")
	body, conv := syscall.UTF16PtrFromString(err.Error())
	if conv == nil {
		_, _, _ = messageBox.Call(0, uintptr(unsafe.Pointer(body)), uintptr(unsafe.Pointer(title)), 0x10)
	}
}

type basicLimits struct {
	processUserTime, jobUserTime         int64
	flags                                uint32
	minimumWorkingSet, maximumWorkingSet uintptr
	maximumProcesses                     uint32
	affinity                             uintptr
	priority, scheduling                 uint32
}

type extendedLimits struct {
	basic                                          basicLimits
	ioCounters                                     [6]uint64
	processMemory, jobMemory, peakProcess, peakJob uintptr
}

func containRuntime(process *os.Process) (func(), error) {
	createJob := kernel.NewProc("CreateJobObjectW")
	configureJob := kernel.NewProc("SetInformationJobObject")
	assignJob := kernel.NewProc("AssignProcessToJobObject")
	job, _, err := createJob.Call(0, 0)
	if job == 0 {
		return nil, err
	}
	closeJob := func() { _, _, _ = closeHandle.Call(job) }
	limits := extendedLimits{basic: basicLimits{flags: 0x00002000}} // JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
	ok, _, err := configureJob.Call(job, 9, uintptr(unsafe.Pointer(&limits)), unsafe.Sizeof(limits))
	if ok == 0 {
		closeJob()
		return nil, err
	}
	handle, err := syscall.OpenProcess(0x0101, false, uint32(process.Pid)) // PROCESS_SET_QUOTA | PROCESS_TERMINATE
	if err != nil {
		closeJob()
		return nil, err
	}
	defer syscall.CloseHandle(handle)
	ok, _, err = assignJob.Call(job, uintptr(handle))
	if ok == 0 {
		closeJob()
		return nil, err
	}
	return closeJob, nil
}
