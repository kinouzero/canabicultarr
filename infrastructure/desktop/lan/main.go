package main

import (
	"crypto/tls"
	"errors"
	"fmt"
	"net"
	"net/http"
	"net/http/httputil"
	"net/netip"
	"net/url"
	"os"
	"path"
	"strconv"
	"strings"
	"time"
)

const proxyHeader = "X-Canabicultarr-Lan-Token"
const clientHeader = "X-Canabicultarr-Lan-Client"

func main() {
	var err error
	switch {
	case len(os.Args) == 1:
		err = serve()
	case len(os.Args) == 4 && os.Args[1] == "issue":
		err = issueCertificate(os.Args[2], os.Args[3])
	default:
		err = errors.New("invalid Desktop HTTPS command")
	}
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}

func serve() error {
	listen := os.Getenv("CANABICULTARR_DESKTOP_LAN_LISTEN")
	public, err := url.Parse(os.Getenv("CANABICULTARR_DESKTOP_LAN_ORIGIN"))
	if err != nil || public.Scheme != "https" || public.Host == "" || public.User != nil || public.Path != "" || public.RawQuery != "" || public.Fragment != "" {
		return errors.New("invalid Desktop HTTPS origin")
	}
	host, port, err := net.SplitHostPort(listen)
	ip, parseErr := netip.ParseAddr(host)
	number, portErr := strconv.Atoi(port)
	if err != nil || parseErr != nil || !ip.Is4() || !ip.IsPrivate() || portErr != nil || number < 1024 || number > 65535 || public.Port() != port {
		return errors.New("invalid Desktop LAN address")
	}
	subnet, err := interfaceNetwork(ip)
	if err != nil {
		return err
	}
	upstreamPort, err := strconv.Atoi(os.Getenv("CANABICULTARR_DESKTOP_LOCAL_PORT"))
	token := os.Getenv("CANABICULTARR_DESKTOP_PROXY_TOKEN")
	if err != nil || upstreamPort < 1024 || upstreamPort > 65535 || len(token) != 64 {
		return errors.New("invalid Desktop proxy configuration")
	}
	cert, err := tls.LoadX509KeyPair(os.Getenv("CANABICULTARR_DESKTOP_LAN_CERT"), os.Getenv("CANABICULTARR_DESKTOP_LAN_KEY"))
	if err != nil {
		return errors.New("the Desktop HTTPS certificate and key could not be loaded")
	}
	now := time.Now()
	if cert.Leaf == nil || cert.Leaf.VerifyHostname(public.Hostname()) != nil || now.Before(cert.Leaf.NotBefore) || now.After(cert.Leaf.NotAfter) {
		return errors.New("the Desktop HTTPS certificate does not match the hostname or is outside its validity dates")
	}
	target := &url.URL{Scheme: "http", Host: fmt.Sprintf("127.0.0.1:%d", upstreamPort)}
	server := &http.Server{
		Addr:              listen,
		Handler:           newGateway(target, public.Host, token, subnet),
		TLSConfig:         &tls.Config{MinVersion: tls.VersionTLS12, Certificates: []tls.Certificate{cert}},
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       2 * time.Minute,
		IdleTimeout:       30 * time.Second,
		MaxHeaderBytes:    32 << 10,
	}
	return server.ListenAndServeTLS("", "")
}

func interfaceNetwork(ip netip.Addr) (netip.Prefix, error) {
	addresses, err := net.InterfaceAddrs()
	if err != nil {
		return netip.Prefix{}, err
	}
	for _, address := range addresses {
		prefix, err := netip.ParsePrefix(address.String())
		if err == nil && prefix.Addr() == ip {
			return prefix.Masked(), nil
		}
	}
	return netip.Prefix{}, errors.New("the selected Desktop LAN address is not assigned to this computer")
}

func newGateway(target *url.URL, host, token string, subnet netip.Prefix) http.Handler {
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.Proxy = nil
	transport.ResponseHeaderTimeout = 30 * time.Second
	transport.MaxResponseHeaderBytes = 64 << 10
	proxy := &httputil.ReverseProxy{
		Transport: transport,
		Rewrite: func(request *httputil.ProxyRequest) {
			request.SetURL(target)
			client, _, _ := net.SplitHostPort(request.In.RemoteAddr)
			request.Out.Header.Set(proxyHeader, token)
			request.Out.Header.Set(clientHeader, client)
		},
		ModifyResponse: func(response *http.Response) error {
			response.Header.Del("X-Canabicultarr-Desktop-Run")
			return nil
		},
		ErrorHandler: func(w http.ResponseWriter, _ *http.Request, _ error) {
			http.Error(w, "Desktop server unavailable", http.StatusBadGateway)
		},
	}
	return http.HandlerFunc(func(w http.ResponseWriter, request *http.Request) {
		client, _, err := net.SplitHostPort(request.RemoteAddr)
		ip, parseErr := netip.ParseAddr(client)
		if err != nil || parseErr != nil || !ip.IsPrivate() || !subnet.Contains(ip) {
			http.Error(w, "Forbidden", http.StatusForbidden)
			return
		}
		if !strings.EqualFold(request.Host, host) || request.URL.IsAbs() || request.Method == http.MethodConnect {
			http.Error(w, "Invalid host or request", http.StatusBadRequest)
			return
		}
		route := strings.ToLower(path.Clean(request.URL.Path))
		if (route == "/api/v1/setup" && request.Method != http.MethodGet && request.Method != http.MethodHead) || strings.HasPrefix(route, "/api/v1/setup/") {
			http.NotFound(w, request)
			return
		}
		const maxRequest = 12 << 20
		if request.ContentLength > maxRequest {
			http.Error(w, "Request too large", http.StatusRequestEntityTooLarge)
			return
		}
		if request.Body != nil && request.ContentLength < 0 {
			// Desktop uploads have a known size. Reject streaming request bodies at this boundary.
			http.Error(w, "Content length required", http.StatusLengthRequired)
			return
		}
		request.Body = http.MaxBytesReader(w, request.Body, maxRequest)
		proxy.ServeHTTP(w, request)
	})
}
