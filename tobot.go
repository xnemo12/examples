package main

import(
	"log"
	"database/sql"
	_ "github.com/lib/pq"
	"github.com/jasonlvhit/gocron"
	"fmt"
	"net/http"
	"bytes"
	"io/ioutil"
)

const (
	DB_USER     = "***"
	DB_PASSWORD = "**********"
	DB_NAME     = "*******"
)

func main() {

	log.Printf(" [*] Scheduler started.")
	task()
	gocron.Every(15).Minutes().Do(task)
	<- gocron.Start()
}

func task() {
	dbinfo := fmt.Sprintf("user=%s password=%s dbname=%s sslmode=disable",
		DB_USER, DB_PASSWORD, DB_NAME)
	db, err := sql.Open("postgres", dbinfo)
	checkErr(err)
	defer db.Close()


	rows, err := db.Query("select count(*) from sms_queue where extract('epoch' from created_at::date) = extract('epoch' from now()::date) and status=0")
	checkErr(err)

	for rows.Next() {
		var count int
		err = rows.Scan(&count)
		checkErr(err)
		log.Printf("Sms count - %3v", count)
		if count>1000 {
			text := fmt.Sprintf("Внимание! Количество неотправленных СМС сообщений: %3v", count)
			body := []byte(text)
			r, _ := http.Post("https://endpoint_for_webhook/report", "application/text", bytes.NewBuffer(body))
			response, _ := ioutil.ReadAll(r.Body)
			log.Printf(string(response))
		}
	}
}

func checkErr(err error) {
	if err != nil {
		panic(err)
	}
}
