package main

// --selftest mode: act as a Secret Service *client* and round-trip a secret
// (OpenSession -> CreateItem -> SearchItems -> GetSecret) against whatever
// daemon owns org.freedesktop.secrets on the session bus. Used by the smoke
// suite and localtest.sh to prove the service end-to-end. Prints
// "SELFTEST PASS" and exits 0 on success.

import (
	"bytes"
	"fmt"
	"os"
	"strconv"
	"time"

	"github.com/godbus/dbus/v5"
)

func stFail(stage string, err error) {
	fmt.Printf("SELFTEST FAIL: %s: %v\n", stage, err)
	os.Exit(1)
}

func runSelftest(busAddr string) {
	var conn *dbus.Conn
	var err error
	if busAddr != "" {
		conn, err = dbus.Connect(busAddr)
	} else {
		conn, err = dbus.ConnectSessionBus()
	}
	if err != nil {
		stFail("connect session bus", err)
	}
	defer conn.Close()
	if err := selftestRoundTrip(conn); err != nil {
		stFail("round trip", err)
	}
	fmt.Println("SELFTEST PASS")
}

// selftestRoundTrip removes its own item and closes its session on every return.
func selftestRoundTrip(conn *dbus.Conn) (result error) {
	svcObj := conn.Object(busName, servicePath)

	var output dbus.Variant
	var session dbus.ObjectPath
	if err := svcObj.Call(ifaceService+".OpenSession", 0, "plain", dbus.MakeVariant("")).Store(&output, &session); err != nil {
		return fmt.Errorf("OpenSession: %w", err)
	}
	defer func() {
		if err := conn.Object(busName, session).Call(ifaceSession+".Close", 0).Err; result == nil && err != nil {
			result = fmt.Errorf("Close: %w", err)
		}
	}()

	// A test must not replace an item from a prior or concurrent invocation.
	attrs := map[string]string{"service": "aicli-selftest", "account": "smoke", "run": strconv.Itoa(os.Getpid()) + "-" + strconv.FormatInt(time.Now().UnixNano(), 10)}
	want := []byte("aicli-secret-service-selftest-value")

	collObj := conn.Object(busName, collPath)
	createProps := map[string]dbus.Variant{
		propLabel:      dbus.MakeVariant("aicli selftest"),
		propAttributes: dbus.MakeVariant(attrs),
	}
	sec := secret{Session: session, Parameters: []byte{}, Value: want, ContentType: "text/plain"}
	var itemP, promptP dbus.ObjectPath
	if err := collObj.Call(ifaceCollection+".CreateItem", 0, createProps, sec, false).Store(&itemP, &promptP); err != nil {
		return fmt.Errorf("CreateItem: %w", err)
	}
	defer func() {
		if err := conn.Object(busName, itemP).Call(ifaceItem+".Delete", 0).Err; result == nil && err != nil {
			result = fmt.Errorf("Delete: %w", err)
		}
	}()

	var unlocked, locked []dbus.ObjectPath
	if err := svcObj.Call(ifaceService+".SearchItems", 0, attrs).Store(&unlocked, &locked); err != nil {
		return fmt.Errorf("SearchItems: %w", err)
	}
	if len(unlocked) == 0 {
		return fmt.Errorf("SearchItems: created item not found")
	}

	var got secret
	if err := conn.Object(busName, unlocked[0]).Call(ifaceItem+".GetSecret", 0, session).Store(&got); err != nil {
		return fmt.Errorf("GetSecret: %w", err)
	}
	if !bytes.Equal(got.Value, want) {
		return fmt.Errorf("GetSecret: secret value mismatch")
	}
	return nil
}
