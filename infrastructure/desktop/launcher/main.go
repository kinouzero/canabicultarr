// The desktop launcher uses the default browser for local setup and supervises the bundled runtime.
package main

import (
	"bytes"
	"context"
	"crypto/rand"
	_ "embed"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"html/template"
	"io"
	"net"
	"net/http"
	"net/http/cookiejar"
	"net/url"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

//go:embed setup.html
var pageHTML string

var page = template.Must(template.New("setup").Parse(pageHTML))
var hexColor = regexp.MustCompile("^#[0-9a-fA-F]{6}$")

type backupSchedule struct {
	Frequency   string `json:"frequency"`
	Directory   string `json:"directory"`
	Keep        int    `json:"keep"`
	HasPassword bool   `json:"hasPassword"`
	LastSuccess int64  `json:"lastSuccess"`
	Failed      bool   `json:"failed"`
}

func (b backupSchedule) LastBackup() string {
	if b.LastSuccess == 0 {
		return ""
	}
	return time.Unix(b.LastSuccess, 0).Local().Format("2006-01-02 15:04")
}

type settings struct {
	LANEnabled             bool           `json:"lanEnabled"`
	LANBind                string         `json:"lanBind"`
	LANPort                string         `json:"lanPort"`
	LANHost                string         `json:"lanHost"`
	LANCert                string         `json:"lanCert"`
	LANKey                 string         `json:"lanKey"`
	LANFingerprint         string         `json:"lanFingerprint"`
	LANAuthority           string         `json:"lanAuthority"`
	LANPreviousAuthority   string         `json:"lanPreviousAuthority"`
	LANPreviousFingerprint string         `json:"lanPreviousFingerprint"`
	BackupSchedule         backupSchedule `json:"backupSchedule"`
	Configured             bool           `json:"configured"`
	Port                   string         `json:"port"`
	Host                   string         `json:"host"`
	SMTPPort               string         `json:"smtpPort"`
	Sender                 string         `json:"sender"`
	SenderName             string         `json:"senderName"`
	Username               string         `json:"username"`
}

func (s settings) LANURL() string {
	if !s.LANEnabled {
		return ""
	}
	return "https://" + s.LANHost + ":" + s.LANPort
}

func (s settings) startErrorKey() string {
	if s.LANEnabled {
		return "lanStartError"
	}
	return "startError"
}

type desktop struct {
	root, data, origin, route string
	settings                  settings
	server                    *exec.Cmd
	done                      chan struct{}
	releaseRuntime            func()
	runID, appOrigin          string
	needsAdministrator        bool
	stopped                   chan struct{}
	mu                        sync.Mutex
}

type view struct {
	Route, Language, Error, Origin, Step, Email, Notice string
	Settings                                            settings
	Colors                                              map[string]template.CSS
	Text                                                map[string]string
}

func main() {
	if err := launch(); err != nil {
		reportLauncherError(err)
		os.Exit(1)
	}
}

func launch() error {
	executable, err := os.Executable()
	if err != nil {
		return err
	}
	root := filepath.Dir(executable)
	data := os.Getenv("CANABICULTARR_DESKTOP_DATA_DIR")
	if data == "" {
		data, err = defaultDataDirectory()
		if err != nil {
			return err
		}
	}
	data, err = filepath.Abs(data)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(data, 0700); err != nil {
		return err
	}
	info, err := os.Stat(data)
	if err != nil || !privateDataDirectory(info) {
		return errors.New("the desktop data directory must be private and owned by this user")
	}
	release, held, err := lockLauncher(data)
	if err != nil {
		return err
	}
	defer release()
	link := filepath.Join(data, "launcher-url")
	if !held {
		for range 20 {
			raw, err := os.ReadFile(link)
			address, parsed := url.Parse(string(raw))
			if err == nil && parsed == nil && address.Scheme == "http" && address.Hostname() == "127.0.0.1" && address.User == nil {
				return openBrowser(address.String())
			}
			time.Sleep(50 * time.Millisecond)
		}
		return errors.New("Canabicultarr is already starting")
	}
	listener, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		return err
	}
	defer listener.Close()
	secret := make([]byte, 24)
	if _, err := rand.Read(secret); err != nil {
		return err
	}
	d := desktop{root: root, data: data, origin: "http://" + listener.Addr().String(), route: "/" + hex.EncodeToString(secret) + "/", stopped: make(chan struct{}, 1)}
	if result, err := d.control("settings", nil); err != nil || json.Unmarshal(result, &d.settings) != nil {
		return errors.New("could not read desktop settings")
	}
	defer func() {
		d.mu.Lock()
		defer d.mu.Unlock()
		d.stop()
	}()
	var startError bool
	if d.settings.Configured {
		startError = d.start() != nil
	}
	if err := os.WriteFile(link, []byte(d.origin+d.route), 0600); err != nil {
		return err
	}
	defer os.Remove(link)
	system, unsubscribe := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer unsubscribe()
	server := &http.Server{Handler: d.handler(startError), ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 10 * time.Second, WriteTimeout: 45 * time.Second}
	serveErr := make(chan error, 1)
	go func() { serveErr <- server.Serve(listener) }()
	if err := openBrowser(d.origin + d.route); err != nil {
		_ = server.Close()
		return err
	}
	select {
	case <-system.Done():
	case <-d.stopped:
	case err := <-serveErr:
		return err
	}
	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()
	return server.Shutdown(ctx)
}

func openBrowser(address string) error {
	if os.Getenv("CANABICULTARR_DESKTOP_NO_BROWSER") == "1" {
		return nil
	}
	return openDefaultBrowser(address)
}

func (d *desktop) php(action string) *exec.Cmd {
	runtime := filepath.Join(d.root, "runtime")
	cmd := exec.Command(filepath.Join(runtime, phpExecutable), filepath.Join(d.root, "project", "infrastructure", "desktop", "run.php"), action)
	cmd.Env = []string{"HOME=" + os.Getenv("HOME"), "PHPRC=" + filepath.Join(runtime, "php.ini"),
		"PHP_INI_SCAN_DIR=" + filepath.Join(runtime, "ini"),
		"CANABICULTARR_CA_FILE=" + filepath.Join(runtime, "cert.pem"), "CANABICULTARR_PHP_EXTENSIONS=" + filepath.Join(runtime, "extensions"),
		"CANABICULTARR_DESKTOP_DATA_DIR=" + d.data, "CANABICULTARR_DESKTOP_RUN_ID=" + d.runID}
	configureRuntimeCommand(cmd, runtime)
	if os.Getenv("CANABICULTARR_DESKTOP_NO_BROWSER") == "1" {
		cmd.Stderr = os.Stderr
	}
	return cmd
}

func (d *desktop) control(action string, body []byte) ([]byte, error) {
	cmd := d.php(action)
	cmd.Stdin = bytes.NewReader(body)
	return cmd.Output()
}

func (d *desktop) start() error {
	port, err := strconv.Atoi(d.settings.Port)
	if err != nil || port < 1024 || port > 65535 {
		return errors.New("invalid application port")
	}
	nonce := make([]byte, 16)
	if _, err := rand.Read(nonce); err != nil {
		return err
	}
	h := hex.EncodeToString(nonce)
	d.runID = h[:8] + "-" + h[8:12] + "-" + h[12:16] + "-" + h[16:20] + "-" + h[20:]
	d.appOrigin = fmt.Sprintf("http://127.0.0.1:%d", port)
	d.server = d.php("serve")
	if os.Getenv("CANABICULTARR_DESKTOP_NO_BROWSER") == "1" {
		d.server.Stdout = os.Stderr
	}
	if err := d.server.Start(); err != nil {
		d.server = nil
		return err
	}
	release, err := containRuntime(d.server.Process)
	if err != nil {
		_ = d.server.Process.Kill()
		_ = d.server.Wait()
		d.server = nil
		return err
	}
	d.releaseRuntime = release
	command := d.server
	done := make(chan struct{})
	d.done = done
	go func() { _ = command.Wait(); close(done) }()
	client := &http.Client{Timeout: 4 * time.Second}
	deadline := time.Now().Add(15 * time.Second)
	for time.Now().Before(deadline) {
		select {
		case <-done:
			d.stop()
			return errors.New("the application server stopped")
		default:
		}
		body, status, err := d.applicationRequest(client, "setup", nil, "")
		var response struct {
			Data struct {
				RequiresSetup bool `json:"requiresSetup"`
			}
		}
		if err == nil && status == 200 && json.Unmarshal(body, &response) == nil {
			d.needsAdministrator = response.Data.RequiresSetup
			return nil
		}
		time.Sleep(150 * time.Millisecond)
	}
	d.stop()
	return errors.New("the application server did not respond")
}

func (d *desktop) stop() {
	if d.server == nil {
		return
	}
	requestRuntimeStop(d.server.Process, d.data, d.runID)
	select {
	case <-d.done:
	case <-time.After(7 * time.Second):
		_ = d.server.Process.Kill()
		<-d.done
	}
	d.server = nil
	d.releaseRuntime()
	d.releaseRuntime = nil
	_ = os.Remove(filepath.Join(d.data, "stop-"+d.runID))
}

func (d *desktop) applicationRequest(client *http.Client, path string, body []byte, csrf string) ([]byte, int, error) {
	method := http.MethodGet
	if body != nil {
		method = http.MethodPost
	}
	req, err := http.NewRequest(method, d.appOrigin+"/api/v1/"+path, bytes.NewReader(body))
	if err != nil {
		return nil, 0, err
	}
	req.Header.Set("Accept", "application/json")
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
		req.Header.Set("X-XSRF-TOKEN", csrf)
	}
	res, err := client.Do(req)
	if err != nil {
		return nil, 0, err
	}
	defer res.Body.Close()
	if res.Header.Get("X-Canabicultarr-Desktop-Run") != d.runID {
		return nil, 0, errors.New("unexpected local instance")
	}
	result, err := io.ReadAll(io.LimitReader(res.Body, 128<<10))
	return result, res.StatusCode, err
}

func (d *desktop) createAdmin(form url.Values, locale string) error {
	token, err := d.control("setup-token", nil)
	if err != nil {
		return err
	}
	jar, _ := cookiejar.New(nil)
	client := &http.Client{Jar: jar, Timeout: 10 * time.Second}
	if _, status, err := d.applicationRequest(client, "auth/csrf", nil, ""); err != nil || status != 204 {
		return errors.New("could not prepare the account form")
	}
	origin, _ := url.Parse(d.appOrigin)
	for _, cookie := range jar.Cookies(origin) {
		if cookie.Name != "XSRF-TOKEN" {
			continue
		}
		csrf, err := url.PathUnescape(cookie.Value)
		if err != nil {
			return err
		}
		payload, _ := json.Marshal(map[string]string{"token": strings.TrimSpace(string(token)), "name": form.Get("name"), "email": form.Get("email"), "password": form.Get("password"), "password_confirmation": form.Get("confirmation"), "locale": locale})
		if _, status, err := d.applicationRequest(client, "setup", payload, csrf); err == nil && status == 201 {
			return nil
		}
	}
	return errors.New("could not create the administrator")
}

func (d *desktop) profileArchive(w http.ResponseWriter, r *http.Request, action string) error {
	controller := http.NewResponseController(w)
	deadline := time.Now().Add(30 * time.Minute)
	_ = controller.SetReadDeadline(deadline)
	_ = controller.SetWriteDeadline(deadline)
	dir, err := os.MkdirTemp(d.data, ".archive-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(dir)
	path := filepath.Join(dir, "profile.zip")
	password, err := archiveInput(w, r, path, action == "restore")
	if err != nil {
		return err
	}
	payload, _ := json.Marshal(map[string]string{"path": path, "password": password})
	if _, err := d.control(action, payload); err != nil {
		return err
	}
	if action == "restore" {
		raw, err := d.control("settings", nil)
		if err != nil {
			return err
		}
		return json.Unmarshal(raw, &d.settings)
	}
	w.Header().Set("Content-Type", "application/zip")
	w.Header().Set("Content-Disposition", `attachment; filename="canabicultarr-desktop-backup.zip"`)
	http.ServeFile(w, r, path)
	return nil
}

func archiveInput(w http.ResponseWriter, r *http.Request, path string, restore bool) (string, error) {
	if !restore {
		r.Body = http.MaxBytesReader(w, r.Body, 2048)
		if err := r.ParseForm(); err != nil {
			return "", err
		}
		return r.PostForm.Get("password"), nil
	}
	r.Body = http.MaxBytesReader(w, r.Body, 9<<30)
	parts, err := r.MultipartReader()
	if err != nil {
		return "", err
	}
	var password string
	var uploaded bool
	for {
		part, err := parts.NextPart()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return "", err
		}
		switch part.FormName() {
		case "password":
			data, err := io.ReadAll(io.LimitReader(part, 257))
			if err != nil || len(data) < 12 || len(data) > 256 || password != "" {
				return "", errors.New("invalid backup password")
			}
			password = string(data)
		case "archive":
			file, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
			if err != nil {
				return "", err
			}
			_, copyErr := io.Copy(file, part)
			if err := errors.Join(copyErr, file.Close()); err != nil {
				return "", err
			}
			uploaded = true
		default:
			return "", errors.New("unexpected restore field")
		}
	}
	if !uploaded || password == "" {
		return "", errors.New("missing restore input")
	}
	return password, nil
}

func (d *desktop) handler(startError bool) http.Handler {
	raw, _ := os.ReadFile(filepath.Join(d.root, "branding", "palette.json"))
	var palette struct{ Colors map[string]string }
	_ = json.Unmarshal(raw, &palette)
	colors := make(map[string]template.CSS)
	for name, value := range palette.Colors {
		if hexColor.MatchString(value) {
			colors[name] = template.CSS(value)
		}
	}
	note, notice := "", ""
	if startError {
		note = d.settings.startErrorKey()
	}
	email := ""
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if "http://"+r.Host != d.origin {
			http.Error(w, "Bad Host", http.StatusBadRequest)
			return
		}
		w.Header().Set("Cache-Control", "no-store")
		// Preserve Origin for same-origin form POSTs without leaking the private setup URL to external sites.
		w.Header().Set("Referrer-Policy", "same-origin")
		w.Header().Set("X-Content-Type-Options", "nosniff")
		w.Header().Set("Content-Security-Policy", "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'")
		if r.Method == http.MethodGet && r.URL.Path == d.route+"logo" {
			http.ServeFile(w, r, filepath.Join(d.root, "branding", "mark.png"))
			return
		}
		if r.Method == http.MethodGet && (r.URL.Path == d.route+"trust-certificate" || r.URL.Path == d.route+"trust-profile") {
			action, mediaType, filename := "export-lan-certificate", "application/pkix-cert", "Canabicultarr-Desktop-CA.cer"
			if r.URL.Path == d.route+"trust-profile" {
				action, mediaType, filename = "export-lan-profile", "application/x-apple-aspen-config", "Canabicultarr-Desktop.mobileconfig"
			}
			d.mu.Lock()
			certificate, err := d.control(action, nil)
			d.mu.Unlock()
			if err != nil {
				http.NotFound(w, r)
				return
			}
			w.Header().Set("Content-Type", mediaType)
			w.Header().Set("Content-Disposition", `attachment; filename="`+filename+`"`)
			_, _ = w.Write(certificate)
			return
		}
		if r.Method == http.MethodGet && r.URL.Path == d.route+"runtime-log" {
			log, err := d.control("runtime-log", nil)
			if err != nil {
				http.NotFound(w, r)
				return
			}
			w.Header().Set("Content-Type", "text/plain; charset=utf-8")
			w.Header().Set("Content-Disposition", `attachment; filename="Canabicultarr-runtime.log"`)
			_, _ = w.Write(log)
			return
		}
		action := strings.TrimPrefix(r.URL.Path, d.route)
		archiveAction := r.URL.Path == d.route+"backup" || r.URL.Path == d.route+"restore"
		if r.URL.Path != d.route && !archiveAction {
			http.NotFound(w, r)
			return
		}
		fr := strings.HasPrefix(strings.ToLower(r.Header.Get("Accept-Language")), "fr")
		language, text := "en", english
		if fr {
			language, text = "fr", french
		}
		d.mu.Lock()
		defer d.mu.Unlock()
		step := "configuration"
		if d.server != nil {
			select {
			case <-d.done:
				d.stop()
				note = d.settings.startErrorKey()
			default:
				step = "ready"
				if d.needsAdministrator {
					step = "administrator"
				}
			}
		}
		if archiveAction {
			if r.Method != http.MethodPost || r.Header.Get("Origin") != d.origin {
				http.Error(w, "Forbidden", http.StatusForbidden)
				return
			}
			if step != "configuration" || (action == "backup") != d.settings.Configured {
				http.Error(w, "Invalid action", http.StatusConflict)
				return
			}
			note, notice = "", ""
			if err := d.profileArchive(w, r, action); err != nil {
				note = action + "Error"
			} else if action == "backup" {
				return
			} else {
				notice = "restored"
			}
			http.Redirect(w, r, d.route, http.StatusSeeOther)
			return
		}
		if r.Method == http.MethodPost {
			if r.Header.Get("Origin") != d.origin {
				http.Error(w, "Forbidden", http.StatusForbidden)
				return
			}
			r.Body = http.MaxBytesReader(w, r.Body, 16<<10)
			if err := r.ParseForm(); err != nil {
				http.Error(w, "Invalid form", http.StatusBadRequest)
				return
			}
			note, notice = "", ""
			switch action := r.PostForm.Get("action"); {
			case action == "gmail" && step == "configuration":
				d.settings = formSettings(r.PostForm, d.settings)
				d.settings.Host, d.settings.SMTPPort = "smtp.gmail.com", "587"
			case (action == "generate-certificate" || action == "replace-certificate" || action == "default-certificate" || action == "remove-certificate") && step == "configuration" && d.settings.Configured:
				draft := formSettings(r.PostForm, d.settings)
				hostname := strings.TrimSpace(draft.LANHost)
				if hostname == "" {
					hostname = strings.TrimSpace(draft.LANBind)
				}
				defaults := action == "default-certificate"
				remove := action == "remove-certificate"
				command := "generate-lan-certificate"
				if remove {
					command = "remove-lan-certificate"
				}
				payload, _ := json.Marshal(map[string]any{
					"hostname": hostname, "replace": action == "replace-certificate" || defaults,
					"defaults": defaults, "confirm": remove,
				})
				var generated settings
				if _, err := d.control(command, payload); err != nil {
					note = "lanCertificateError"
					if remove {
						note = "clearCertificateError"
					}
				} else if raw, err := d.control("settings", nil); err != nil || json.Unmarshal(raw, &generated) != nil {
					note = "lanCertificateError"
				} else {
					d.settings = draft
					d.settings.LANHost, d.settings.LANCert, d.settings.LANKey = generated.LANHost, generated.LANCert, generated.LANKey
					d.settings.LANFingerprint = generated.LANFingerprint
					d.settings.LANAuthority = generated.LANAuthority
					d.settings.LANPreviousAuthority = generated.LANPreviousAuthority
					d.settings.LANPreviousFingerprint = generated.LANPreviousFingerprint
					notice = "lanCertificateGenerated"
					if defaults {
						d.settings.LANBind, d.settings.LANPort = generated.LANBind, generated.LANPort
					}
					if remove {
						d.settings.LANEnabled = false
						notice = "certificateCleared"
					}
				}
			case action == "save" && step == "configuration":
				d.settings = formSettings(r.PostForm, d.settings)
				fields := map[string]string{"port": d.settings.Port, "host": d.settings.Host, "smtpPort": d.settings.SMTPPort, "sender": d.settings.Sender, "senderName": d.settings.SenderName, "username": d.settings.Username, "password": r.PostForm.Get("password")}
				fields["lanEnabled"] = "0"
				if d.settings.LANEnabled {
					fields["lanEnabled"] = "1"
					for _, name := range []string{"lanBind", "lanPort", "lanHost", "lanCert", "lanKey"} {
						fields[name] = r.PostForm.Get(name)
					}
				}
				if d.settings.Host == "smtp.gmail.com" {
					fields["password"] = strings.Join(strings.Fields(fields["password"]), "")
					if fields["username"] == "" {
						fields["username"] = d.settings.Sender
					}
				}
				data, _ := json.Marshal(fields)
				if _, err := d.control("configure", data); err != nil {
					note = "settingsError"
					if d.settings.LANEnabled {
						note = "lanSettingsError"
					}
				} else {
					d.settings.Configured = true
					if err := d.start(); err != nil {
						note = d.settings.startErrorKey()
					}
				}
			case action == "schedule" && step == "configuration" && d.settings.Configured:
				fields := map[string]string{}
				for _, name := range []string{"frequency", "directory", "keep", "password"} {
					fields[name] = r.PostForm.Get(name)
				}
				data, _ := json.Marshal(fields)
				if _, err := d.control("configure-backups", data); err != nil {
					note = "scheduleError"
				} else if raw, err := d.control("settings", nil); err != nil || json.Unmarshal(raw, &d.settings) != nil {
					note = "scheduleError"
				} else {
					notice = "scheduleSaved"
				}
			case action == "administrator" && step == "administrator":
				if err := d.createAdmin(r.PostForm, language); err != nil {
					note = "administratorError"
				} else {
					d.needsAdministrator = false
					email = r.PostForm.Get("email")
				}
			case action == "edit":
				d.stop()
				if raw, err := d.control("settings", nil); err == nil {
					_ = json.Unmarshal(raw, &d.settings)
				}
			case action == "stop":
				d.stop()
				_ = page.Execute(w, view{Route: d.route, Language: language, Step: "stopped", Text: text, Colors: colors})
				d.stopped <- struct{}{}
				return
			default:
				http.Error(w, "Invalid action", http.StatusBadRequest)
				return
			}
			http.Redirect(w, r, d.route, http.StatusSeeOther)
			return
		}
		if r.Method != http.MethodGet {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}
		_ = page.Execute(w, view{Route: d.route, Language: language, Error: text[note], Notice: text[notice], Origin: d.appOrigin, Step: step, Email: email, Settings: d.settings, Colors: colors, Text: text})
	})
}

func formSettings(form url.Values, current settings) settings {
	draft := settings{Configured: current.Configured, BackupSchedule: current.BackupSchedule,
		LANEnabled: form.Get("lanEnabled") == "1", LANBind: form.Get("lanBind"), LANPort: form.Get("lanPort"),
		LANHost: form.Get("lanHost"), LANCert: form.Get("lanCert"), LANKey: form.Get("lanKey"), Port: form.Get("port"), Host: form.Get("host"), SMTPPort: form.Get("smtpPort"), Sender: form.Get("sender"), SenderName: form.Get("senderName"), Username: form.Get("username")}
	if draft.LANCert == current.LANCert && draft.LANKey == current.LANKey {
		draft.LANFingerprint = current.LANFingerprint
		draft.LANAuthority = current.LANAuthority
		draft.LANPreviousAuthority = current.LANPreviousAuthority
		draft.LANPreviousFingerprint = current.LANPreviousFingerprint
	}
	return draft
}

var english = map[string]string{
	"settingsGeneral":        "General",
	"settingsMail":           "Email",
	"customCertificate":      "Custom PEM certificate",
	"defaultCertificate":     "Restore default name and certificate",
	"defaultCertificateHelp": "Use this computer’s network name and port 8443, and require devices to trust the newly generated certificate.",
	"clearCertificate":       "Remove certificate from Desktop",
	"clearCertificateHelp":   "Turn off local network HTTPS and clear the certificate from Desktop. Certificates installed on other devices can be removed in their trust settings.",
	"clearCertificateError":  "The certificate could not be removed.",
	"certificateCleared":     "Certificate removed and local network HTTPS disabled.",
	"runtimeLog":             "Download startup log",
	"settings":               "Set up Canabicultarr", "administrator": "Create the first administrator", "ready": "Canabicultarr is ready", "stopped": "The local server has stopped.",
	"port": "Local port", "host": "SMTP server", "smtpPort": "SMTP port", "sender": "Sender email", "senderName": "Sender name", "username": "SMTP username", "password": "SMTP password",
	"gmail": "Use Gmail", "gmailHelp": "Create a Google app password", "save": "Save and start", "name": "Name", "email": "Account email", "accountPassword": "Account password", "confirmation": "Confirm password",
	"create": "Create administrator", "activation": "Open the activation email on this computer before signing in.", "open": "Open Canabicultarr", "edit": "Change settings", "stop": "Stop server",
	"settingsError": "Check the local port and SMTP details.", "startError": "The local server could not start. Check that the selected port is available.",
	"administratorError": "Check the administrator fields and SMTP configuration, then try again.",
	"backup":             "Download encrypted backup", "restore": "Restore backup", "archive": "Backup file",
	"archivePassword": "Backup password", "restored": "Backup restored. Save and start to open it.",
	"backupError":  "The backup could not be created. Check the password, disk space and local documents.",
	"restoreError": "The backup could not be restored. Check the file, password and free disk space.",
	"schedule":     "Automatic backups", "frequency": "Frequency", "off": "Off", "daily": "Daily", "weekly": "Weekly",
	"destination": "Backup directory", "retention": "Archives to keep", "scheduleSave": "Save backup settings",
	"passwordStored": "Leave blank to keep the saved password", "lastBackup": "Last successful backup",
	"scheduleFailed": "The last automatic backup did not complete.", "scheduleSaved": "Automatic backup settings saved.",
	"scheduleError": "Check the backup directory, archive count and password.",
	"lan":           "Local network HTTPS", "lanEnabled": "Allow access from this local network", "lanBind": "Private address of this computer",
	"lanPort": "HTTPS port", "lanHost": "Certificate hostname", "lanCert": "PEM certificate chain path", "lanKey": "PEM private key path",
	"lanHelp":               "Use a certificate trusted by your phone. Complete first administrator setup on this computer.",
	"customCertificateHelp": "For public trust, enter your domain under Certificate hostname. Choose its full PEM chain and key. Desktop picks up renewals at the same paths automatically.",
	"lanSettingsError":      "Check the network fields, PEM certificate and key, and SMTP settings.",
	"lanStartError":         "Could not start. Check the selected interface, HTTPS port and certificate, or turn off local network access.",
	"generateCertificate":   "Generate a local certificate", "downloadTrust": "Download the trust certificate",
	"lanCertificateError":     "The certificate could not be generated. Check the hostname or private IP address.",
	"lanCertificateGenerated": "Certificate generated. Download it and approve it on your phone before connecting.",
	"lanTrustHelp":            "A new certificate requires approval again on your devices.",
	"trustCertificate":        "Trust the certificate",
	"trustDesktopHelp":        "On a computer, import the certificate into the trusted authorities for your system or browser.",
	"trustIphoneTitle":        "iPhone or iPad",
	"trustIphoneInstall":      "Open the shared profile on your iPhone. Go to Settings → Profile Downloaded and tap Install.",
	"trustIphoneEnable":       "Go to Settings → General → About → Certificate Trust Settings. Under Enable Full Trust for Root Certificates, turn on the certificate shown below and confirm.",
	"trustIphoneNote":         "Safari requires this second step even after the profile is installed.",
	"trustAndroidHelp":        "On Android, save the certificate and search Settings for Install a certificate. Select CA certificate.",
	"trustIphoneProfile":      "Download the iPhone or iPad profile",
	"replaceCertificate":      "Replace the local certificate",
	"replaceCertificateHelp":  "Approve the new certificate on each device, then remove the old one.",
	"removeCertificate":       "Remove a certificate",
	"previousCertificate":     "Previous certificate",
	"removeDesktopHelp":       "On a computer, remove the matching certificate from the system or browser trust store where you imported it.",
	"removeIphoneHelp":        "On iPhone or iPad, remove the matching profile in Settings → General → VPN & Device Management.",
	"removeAndroidHelp":       "On Android, search Settings for User credentials and remove the matching certificate.",
	"removePairingHelp":       "To disconnect a phone from your account, remove its registration in My account → Security → Mobile devices.",
}

var french = map[string]string{
	"settingsGeneral":        "Général",
	"settingsMail":           "Courriel",
	"customCertificate":      "Certificat PEM personnalisé",
	"defaultCertificate":     "Rétablir le nom et le certificat par défaut",
	"defaultCertificateHelp": "Utilise le nom réseau de cet ordinateur et le port 8443. Il faudra approuver le nouveau certificat sur tes appareils.",
	"clearCertificate":       "Supprimer le certificat de Desktop",
	"clearCertificateHelp":   "Désactive le HTTPS sur le réseau local et retire le certificat de Desktop. Le retrait sur les autres appareils se fait dans leurs réglages de confiance.",
	"clearCertificateError":  "Le certificat n’a pas pu être supprimé.",
	"certificateCleared":     "Le certificat a été retiré et le HTTPS sur le réseau local est désactivé.",
	"runtimeLog":             "Télécharger le journal de démarrage",
	"settings":               "Configurer Canabicultarr", "administrator": "Créer le premier administrateur", "ready": "Canabicultarr est prêt", "stopped": "Le serveur local est arrêté.",
	"port": "Port local", "host": "Serveur SMTP", "smtpPort": "Port SMTP", "sender": "Adresse d’expédition", "senderName": "Nom d’expédition", "username": "Identifiant SMTP", "password": "Mot de passe SMTP",
	"gmail": "Utiliser Gmail", "gmailHelp": "Créer un mot de passe d’application Google", "save": "Enregistrer et démarrer", "name": "Nom", "email": "Adresse e-mail du compte", "accountPassword": "Mot de passe du compte", "confirmation": "Confirmer le mot de passe",
	"create": "Créer le compte administrateur", "activation": "Ouvrez le courriel d’activation sur cet ordinateur avant de vous connecter.", "open": "Ouvrir Canabicultarr", "edit": "Modifier les réglages", "stop": "Arrêter le serveur",
	"settingsError": "Vérifiez le port local et les réglages SMTP.", "startError": "Le serveur local n’a pas démarré. Vérifiez que le port est disponible.",
	"administratorError": "Vérifiez les champs du compte et les réglages SMTP, puis réessayez.",
	"backup":             "Télécharger la sauvegarde chiffrée", "restore": "Restaurer une sauvegarde", "archive": "Fichier de sauvegarde",
	"archivePassword": "Mot de passe de la sauvegarde", "restored": "Sauvegarde restaurée. Enregistrez et démarrez pour l’ouvrir.",
	"backupError":  "La sauvegarde a échoué. Vérifiez le mot de passe, l’espace disponible et les documents locaux.",
	"restoreError": "La restauration a échoué. Vérifiez le fichier, le mot de passe et l’espace disponible.",
	"schedule":     "Sauvegardes automatiques", "frequency": "Fréquence", "off": "Désactivées", "daily": "Chaque jour", "weekly": "Chaque semaine",
	"destination": "Dossier de sauvegarde", "retention": "Archives à conserver", "scheduleSave": "Enregistrer la planification",
	"passwordStored": "Laisser vide pour conserver le mot de passe enregistré", "lastBackup": "Dernière sauvegarde réussie",
	"scheduleFailed": "La dernière sauvegarde automatique n’a pas abouti.", "scheduleSaved": "Planification enregistrée.",
	"scheduleError": "Vérifiez le dossier, le nombre d’archives et le mot de passe.",
	"lan":           "HTTPS sur le réseau local", "lanEnabled": "Autoriser l’accès depuis ce réseau local", "lanBind": "Adresse privée de cet ordinateur",
	"lanPort": "Port HTTPS", "lanHost": "Nom d’hôte du certificat", "lanCert": "Chemin de la chaîne de certificats PEM", "lanKey": "Chemin de la clé privée PEM",
	"lanHelp":               "Utilisez un certificat reconnu par votre téléphone. Créez le premier administrateur sur cet ordinateur.",
	"customCertificateHelp": "Pour utiliser un certificat public, renseignez votre domaine comme nom d’hôte du certificat. Indiquez sa chaîne PEM complète et sa clé. Desktop détecte les renouvellements enregistrés aux mêmes chemins.",
	"lanSettingsError":      "Vérifiez les réglages réseau, le certificat PEM, la clé et les réglages SMTP.",
	"lanStartError":         "Le démarrage a échoué. Vérifiez l’interface, le port HTTPS et le certificat, ou désactivez l’accès réseau.",
	"generateCertificate":   "Créer un certificat local", "downloadTrust": "Télécharger le certificat de confiance",
	"lanCertificateError":     "Le certificat n’a pas pu être créé. Vérifiez le nom d’hôte ou l’adresse IP privée.",
	"lanCertificateGenerated": "Certificat créé. Téléchargez-le et approuvez-le sur votre téléphone avant de vous connecter.",
	"lanTrustHelp":            "Si vous créez un nouveau certificat, vous devrez l’approuver sur vos appareils.",
	"trustCertificate":        "Approuver le certificat",
	"trustDesktopHelp":        "Sur ordinateur, importe le certificat dans les autorités de confiance de ton système ou de ton navigateur.",
	"trustIphoneTitle":        "iPhone ou iPad",
	"trustIphoneInstall":      "Ouvre le profil partagé sur l’iPhone. Va dans Réglages → Profil téléchargé, puis touche Installer.",
	"trustIphoneEnable":       "Va dans Réglages → Général → Informations → Réglages des certificats. Sous « Activer la confiance totale pour les certificats racine », active le certificat indiqué ci-dessous et confirme.",
	"trustIphoneNote":         "Safari exige cette deuxième étape même quand le profil est déjà installé.",
	"trustAndroidHelp":        "Sur Android, enregistre le certificat et recherche Installer un certificat dans les réglages. Choisis Certificat d’autorité de certification.",
	"trustIphoneProfile":      "Télécharger le profil pour iPhone ou iPad",
	"replaceCertificate":      "Remplacer le certificat local",
	"replaceCertificateHelp":  "Approuve le nouveau certificat sur chaque appareil, puis retire l’ancien.",
	"removeCertificate":       "Retirer un certificat",
	"previousCertificate":     "Ancien certificat",
	"removeDesktopHelp":       "Sur ordinateur, retire le certificat correspondant du magasin de confiance où tu l’as importé.",
	"removeIphoneHelp":        "Sur iPhone ou iPad, supprime le profil correspondant dans Réglages → Général → VPN et gestion de l’appareil.",
	"removeAndroidHelp":       "Sur Android, recherche Identifiants utilisateur dans les réglages et retire le certificat correspondant.",
	"removePairingHelp":       "Pour détacher le téléphone du compte, retire son inscription dans Mon compte → Sécurité → Appareils mobiles.",
}
