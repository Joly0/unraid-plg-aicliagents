//go:build linux

package main

import (
	"bufio"
	"bytes"
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/godbus/dbus/v5"
)

func TestCorruptKeyringLoadsEmptyWithoutChangingTheFile(t *testing.T) {
	store := filepath.Join(t.TempDir(), "keyring.json")
	want := []byte("{broken keyring\n")
	if err := os.WriteFile(store, want, 0o600); err != nil {
		t.Fatal(err)
	}
	d := &daemon{storeF: store, items: map[string]*item{"old": {ID: "old"}}, nextID: 99}
	d.load()
	if len(d.items) != 0 || d.nextID != 1 {
		t.Fatalf("corrupt store retained stale state: %#v, next ID %d", d.items, d.nextID)
	}
	got, err := os.ReadFile(store)
	if err != nil || !bytes.Equal(got, want) {
		t.Fatalf("load changed corrupt evidence: %q, %v", got, err)
	}
}

// A private session bus is required. Never use DBUS_SESSION_BUS_ADDRESS here.
func testBus(t *testing.T) string {
	t.Helper()
	path, err := exec.LookPath("dbus-daemon")
	if err != nil {
		t.Fatal("dbus-daemon is required for the isolated Secret Service test")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	t.Cleanup(cancel)
	cmd := exec.CommandContext(ctx, path, "--session", "--nofork", "--print-address=1", "--address=unix:path="+filepath.Join(t.TempDir(), "bus"))
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		t.Fatal(err)
	}
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	if err := cmd.Start(); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		_ = cmd.Process.Kill()
		_ = cmd.Wait()
	})
	address, err := bufio.NewReader(stdout).ReadString('\n')
	if err != nil {
		t.Fatalf("private bus did not start: %v: %s", err, stderr.String())
	}
	return strings.TrimSpace(address)
}

func TestSelftestRemovesItsItemAndKeepsExistingSecrets(t *testing.T) {
	address := testBus(t)
	server, err := dbus.Connect(address)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = server.Close() })
	reply, err := server.RequestName(busName, dbus.NameFlagDoNotQueue)
	if err != nil || reply != dbus.RequestNameReplyPrimaryOwner {
		t.Fatalf("cannot own private test service: %v, %v", reply, err)
	}
	store := filepath.Join(t.TempDir(), "keyring.json")
	// This has the old selftest attributes. The selftest must not replace it.
	original := &item{ID: "1", Attributes: map[string]string{"service": "aicli-selftest", "account": "smoke"}, Secret: []byte("existing-token"), Content: "text/plain"}
	d := &daemon{conn: server, storeF: store, items: map[string]*item{"1": original}, nextID: 2, sessions: map[dbus.ObjectPath]bool{}}
	d.save()
	for _, export := range []struct {
		value interface{}
		path  dbus.ObjectPath
		iface string
	}{
		{&svc{d}, servicePath, ifaceService},
		{&coll{d}, collPath, ifaceCollection},
	} {
		if err := server.Export(export.value, export.path, export.iface); err != nil {
			t.Fatal(err)
		}
	}
	d.exportItem("1")
	client, err := dbus.Connect(address)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = client.Close() })
	if err := selftestRoundTrip(client); err != nil {
		t.Fatal(err)
	}
	d.mu.Lock()
	sessions := len(d.sessions)
	d.mu.Unlock()
	if sessions != 0 {
		t.Fatalf("selftest left %d open sessions", sessions)
	}
	reloaded := &daemon{storeF: store}
	reloaded.load()
	if len(reloaded.items) != 1 || reloaded.items["1"] == nil || !bytes.Equal(reloaded.items["1"].Secret, []byte("existing-token")) {
		t.Fatalf("selftest changed an existing secret or left its own item: %#v", reloaded.items)
	}
}

func TestKeyringPersistsUnderANonRootUser(t *testing.T) {
	root := t.TempDir()
	// Go makes the test parent private. Permit the child user to reach this
	// test's executable, while the child's home remains private to that user.
	for _, dir := range []string{filepath.Dir(root), root} {
		if err := os.Chmod(dir, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	home := filepath.Join(root, "home")
	if err := os.Mkdir(home, 0o700); err != nil {
		t.Fatal(err)
	}
	uid, gid := os.Getuid(), os.Getgid()
	if uid == 0 {
		uid, gid = 65534, 65534
		if err := os.Chown(home, uid, gid); err != nil {
			t.Fatal(err)
		}
	}
	executable, err := os.Executable()
	if err != nil {
		t.Fatal(err)
	}
	raw, err := os.ReadFile(executable)
	if err != nil {
		t.Fatal(err)
	}
	helper := filepath.Join(root, "store-test")
	if err := os.WriteFile(helper, raw, 0o755); err != nil {
		t.Fatal(err)
	}
	store := filepath.Join(home, ".local", "share", "aicli-keyring", "keyring.json")
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, helper, "-test.run=^TestNonRootStoreHelper$")
	cmd.Env = append(os.Environ(), "AICLI_KEYRING_TEST_STORE="+store)
	if os.Getuid() == 0 {
		cmd.SysProcAttr = &syscall.SysProcAttr{Credential: &syscall.Credential{Uid: uint32(uid), Gid: uint32(gid)}}
	}
	if output, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("non-root keyring round trip failed: %v: %s", err, output)
	}
	info, err := os.Stat(store)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 || int(info.Sys().(*syscall.Stat_t).Uid) != uid {
		t.Fatalf("keyring mode/owner = %v/%d, want 0600/%d", info.Mode(), info.Sys().(*syscall.Stat_t).Uid, uid)
	}
}

func TestNonRootStoreHelper(t *testing.T) {
	store := os.Getenv("AICLI_KEYRING_TEST_STORE")
	if store == "" {
		return // Runs only as the child of TestKeyringPersistsUnderANonRootUser.
	}
	if os.Getuid() == 0 {
		t.Fatal("test helper must run as a non-root user")
	}
	d := &daemon{storeF: store, items: map[string]*item{"1": {ID: "1", Secret: []byte("temporary-test-value")}}, nextID: 2}
	d.save()
	reloaded := &daemon{storeF: store}
	reloaded.load()
	if len(reloaded.items) != 1 || reloaded.items["1"] == nil || reloaded.nextID != 2 || !bytes.Equal(reloaded.items["1"].Secret, []byte("temporary-test-value")) {
		t.Fatalf("keyring did not round trip: %#v", reloaded.items)
	}
}
