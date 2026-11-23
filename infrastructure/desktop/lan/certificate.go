package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"math/big"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"
)

var dnsLabel = regexp.MustCompile("^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$")

const certificateCheckInterval = time.Minute

type certificateSource struct {
	host, certPath, keyPath string
	mu                      sync.Mutex
	current                 *tls.Certificate
	nextCheck               time.Time
}

func newCertificateSource(host, certPath, keyPath string) (*certificateSource, error) {
	source := &certificateSource{host: host, certPath: certPath, keyPath: keyPath}
	now := time.Now()
	certificate, err := source.load(now)
	if err != nil {
		return nil, err
	}
	source.current = certificate
	source.nextCheck = now.Add(certificateCheckInterval)
	return source, nil
}

func (s *certificateSource) load(now time.Time) (*tls.Certificate, error) {
	certificate, err := tls.LoadX509KeyPair(s.certPath, s.keyPath)
	if err != nil {
		return nil, errors.New("the Desktop HTTPS certificate and key could not be loaded")
	}
	if certificate.Leaf == nil {
		certificate.Leaf, err = x509.ParseCertificate(certificate.Certificate[0])
		if err != nil {
			return nil, errors.New("the Desktop HTTPS certificate could not be parsed")
		}
	}
	if certificate.Leaf.VerifyHostname(s.host) != nil || !certificateValidAt(certificate.Leaf, now) {
		return nil, errors.New("the Desktop HTTPS certificate does not match the hostname or is outside its validity dates")
	}
	return &certificate, nil
}

func (s *certificateSource) getCertificate(_ *tls.ClientHelloInfo) (*tls.Certificate, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	now := time.Now()
	if !now.Before(s.nextCheck) {
		s.nextCheck = now.Add(certificateCheckInterval)
		// The issuer may replace the PEM files one at a time. Keep serving the
		// last valid pair while a replacement is incomplete.
		if replacement, err := s.load(now); err == nil {
			s.current = replacement
		}
	}
	if !certificateValidAt(s.current.Leaf, now) {
		return nil, errors.New("the Desktop HTTPS certificate is outside its validity dates")
	}
	return s.current, nil
}

func certificateValidAt(certificate *x509.Certificate, now time.Time) bool {
	return !now.Before(certificate.NotBefore) && !now.After(certificate.NotAfter)
}

func issueCertificate(host, directory string) error {
	ip := net.ParseIP(host)
	if ip == nil {
		if len(host) > 253 {
			return errors.New("invalid certificate hostname")
		}
		for _, label := range strings.Split(strings.ToLower(host), ".") {
			if !dnsLabel.MatchString(label) {
				return errors.New("invalid certificate hostname")
			}
		}
	} else if ip.To4() == nil || !ip.IsPrivate() {
		return errors.New("certificate IP address must be private IPv4")
	}

	caKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return err
	}
	serverKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return err
	}
	caSerial, err := certificateSerial()
	if err != nil {
		return err
	}
	serverSerial, err := certificateSerial()
	if err != nil {
		return err
	}
	now := time.Now()
	root := &x509.Certificate{
		SerialNumber:          caSerial,
		Subject:               pkix.Name{CommonName: "Canabicultarr Desktop " + caSerial.Text(16)[:8]},
		NotBefore:             now.Add(-5 * time.Minute),
		NotAfter:              now.AddDate(2, 0, 0),
		KeyUsage:              x509.KeyUsageCertSign | x509.KeyUsageCRLSign,
		IsCA:                  true,
		BasicConstraintsValid: true,
		MaxPathLenZero:        true,
	}
	certificate := &x509.Certificate{
		SerialNumber:          serverSerial,
		Subject:               pkix.Name{CommonName: host},
		NotBefore:             root.NotBefore,
		NotAfter:              now.Add(365 * 24 * time.Hour),
		KeyUsage:              x509.KeyUsageDigitalSignature,
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		BasicConstraintsValid: true,
	}
	if ip == nil {
		certificate.DNSNames = []string{host}
	} else {
		certificate.IPAddresses = []net.IP{ip}
	}
	caDER, err := x509.CreateCertificate(rand.Reader, root, root, &caKey.PublicKey, caKey)
	if err != nil {
		return err
	}
	// The encoded CA includes the generated subject key identifier used by the leaf.
	issuer, err := x509.ParseCertificate(caDER)
	if err != nil {
		return err
	}
	serverDER, err := x509.CreateCertificate(rand.Reader, certificate, issuer, &serverKey.PublicKey, caKey)
	if err != nil {
		return err
	}
	// The root key only exists in this process. The Desktop runtime only needs the leaf key.
	keyDER, err := x509.MarshalPKCS8PrivateKey(serverKey)
	if err != nil {
		return err
	}
	for name, contents := range map[string][]byte{
		"trust.cer":  caDER,
		"server.pem": pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: serverDER}),
		"server.key": pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: keyDER}),
	} {
		file, err := os.OpenFile(filepath.Join(directory, name), os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
		if err != nil {
			return err
		}
		_, writeErr := file.Write(contents)
		if err := errors.Join(writeErr, file.Close()); err != nil {
			return err
		}
	}
	return nil
}

func certificateSerial() (*big.Int, error) {
	serial, err := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 127))
	if err != nil {
		return nil, err
	}
	return serial.SetBit(serial, 127, 1), nil
}
