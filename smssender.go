package main

import (
	"log"
	"fmt"
	"encoding/xml"
	"time"
	"database/sql"
	_ "github.com/lib/pq"
	"github.com/jasonlvhit/gocron"
	"github.com/tkanos/gonfig"
	"net/http"
	"net/url"
	"io/ioutil"
	"os"
	"io"
)

// <response>
// 	<action>sendmessage</action>
// 	<data>
// 		<acceptreport>
// 			<statuscode>0</statuscode>
// 			<statusmessage>Message accepted for delivery</statusmessage>
// 			<messageid>B6C2E631-34AB-45FA-871A-638C02AD96A9</messageid>
// 			<originator>INFO_KAZ</originator>
// 			<recipient>77776323616</recipient>
// 			<messagetype>SMS:TEXT</messagetype>
// 			<messagedata>Test message http.</messagedata>
// 		</acceptreport>
// 	</data>
// </response>

type Response struct {
	XMLName xml.Name `xml:"response"`
	Action string `xml:"action"`
	Data struct {
		XMLName xml.Name `xml:"data"`
		Acceptreport struct{
			XMLName xml.Name `xml:"acceptreport"`
			Statuscode string `xml:"statuscode"`
			Statusmessage string `xml:"statusmessage"`
			Messageid string `xml:"messageid"`
			Originator string `xml:"originator"`
			Recipient string `xml:"recipient"`
			Messagetype string `xml:"messagetype"`
			Messagedata string `xml:"messagedata"`
		}
	}
}

type Configuration struct {
	Host string
	User string
	Password string
	Name string
	SMSAction string 
  	SMSUsername string
	SMSPassword string
	SMSMessagetype string
	SMSOriginator string
}

type SMS struct {
	Id int
	Msisdn string
	Text string 
	CreatedAt string
	Attempts int
}

func main(){

	configuration := Configuration{}
	err := gonfig.GetConf("/opt/GOSMS/config.json", &configuration)
	checkErr(err)

	f, err := os.OpenFile("/home/james/logs/smsgo.log", os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
	checkErr(err)
	defer f.Close()
	mw := io.MultiWriter(os.Stdout, f)
	log.SetOutput(mw)

	gocron.Every(1).Second().Do(task, configuration)

	log.Printf("[*] SMSSender started.")

	<- gocron.Start()
	//task(configuration)
}

func task(configuration Configuration){

	var dbinfo = fmt.Sprintf("host=%s user=%s password=%s dbname=%s sslmode=disable",
		configuration.Host, configuration.User, configuration.Password, configuration.Name)

	db, err := sql.Open("postgres", dbinfo)
	checkErr(err)
	defer db.Close()

	query := `SELECT id, msisdn, text, created_at, attempts FROM sms_queue WHERE created_at::date=now()::date and status=0 and smsc_id is null ORDER BY id ASC, priority DESC`
	rows, err := db.Query(query)
	checkErr(err)
	for rows.Next() {
		var sms SMS
		err = rows.Scan(&sms.Id, &sms.Msisdn, &sms.Text, &sms.CreatedAt, &sms.Attempts)
		checkErr(err)
		log.Printf("{'id': %3v, 'msisdn': %s, 'text': %s, 'created_at': %s}", sms.Id, sms.Msisdn, sms.Text, sms.CreatedAt)
		sendMessage(db, sms, configuration)
	}
}

func sendMessage(db *sql.DB, sms SMS, configuration Configuration){
	
    resp, err := http.PostForm("http://212.124.121.186:9507/api", url.Values{
		"action": {configuration.SMSAction}, 
		"username": {configuration.SMSUsername},
		"password": {configuration.SMSPassword},
		"recipient": {sms.Msisdn},
		"messagetype": {configuration.SMSMessagetype},
		"originator": {configuration.SMSOriginator},
		"messagedata": {sms.Text},
	})
	checkErr(err)
	defer resp.Body.Close()
  	
	byteValue, _ := ioutil.ReadAll(resp.Body)
	var response Response
	xml.Unmarshal(byteValue, &response)
	log.Printf(response.Data.Acceptreport.Messageid);
	messageId := response.Data.Acceptreport.Messageid
	updateMessage(db, sms, messageId)
}

func updateMessage(db *sql.DB, sms SMS, messageId string){
	dateTime := time.Now().Format("2006-01-02 15:04:05")
	
	_, err := db.Exec(`UPDATE sms_queue set send_at=$1, submitted_at=$2, status=$3, attempts=$4, smsc_id=$5 WHERE id=$6`,
			dateTime, dateTime, 1, sms.Attempts + 1, messageId, sms.Id)

	checkErr(err)
}

func checkErr(err error) {
	if err != nil {
		log.Fatal(err)
		//panic(err)
	}
}
