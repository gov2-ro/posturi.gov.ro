# **posturi.gov.ro**

see [gov2ro Github scraping.docx](https://docs.google.com/document/d/1DpAwGfp4lKbOuSsWmaJWmQOfZ1DC3aNr/edit#heading=h.gak0sunih5p9)

vezi și [https://amepip.gov.ro/anunturi-de-selectie/](https://amepip.gov.ro/anunturi-de-selectie/) 

## **Roadmap**

- [x] ~~Scrape index~~  
      - [x] ~~save each page~~  
- [x] ~~Fetch details~~   
- [x] ~~Download attachments~~   
- [x] ~~Test LLM APIs for converting text to JobPosting schema~~  
      - [ ] saved data exploration  
- [ ] Build UI   
- [ ] Detect multiple jobs per posting  
- [ ] build better prompt for api

### Enhancements

- email / sms notifications \- free for 3, add to calendar

### Extra Stats

- detect org CUI?  
- geolocation  
- timeline  
- type of jobs

## **UI**

- browse by location & job type \- go olx  
- notifications  
- stats / analytics 

- Anuntul a expirat?  
- Locatie: Buzău  
- Nivel: Funcții de execuție  
- Tip: Permanent  
- Angajator: Instituții locale

- Scopul funcţiei publice vacante, conform fişei postului  
- Salariul de funcție  
- Sarcinile de bază ale funcţiei publice vacante, conform fişei postului:  
- Tip de angajare  
- Condiţiile de participare la concurs  
- Cerinţe specifice pentru participare la concurs  
- Documente ce urmează a fi prezentate  
- Modalitatea de depunere a documentelor  
- Bibliografia concursului  
- Funcții publice similare

## **Reference**

- [posturigov.ro](https://www.posturigov.ro/)   
- [github.com/peviitor-ro](https://github.com/peviitor-ro) \- [peviitor-ro/Scrapers ... /sites/guvernulromaniei\_scraper.py](https://github.com/peviitor-ro/Scrapers_Matei/blob/bf4823e57ff8f40734a1445d913d370de6989b46/sites/guvernulromaniei_scraper.py#L13)  
- [gov.uk/find-a-job](https://www.gov.uk/find-a-job)   
- [cariere.gov.md/](https://cariere.gov.md/)   
- [usajobs.gov/](https://www.usajobs.gov/)   
- [careers.govt.nz](https://www.careers.govt.nz/)  / [Job vacancy and recruitment websites](https://www.careers.govt.nz/job-hunting/finding-work/job-vacancy-and-recruitment-websites/)   
- see also: [https://mediere.anofm.ro/app/module/mediere/jobs](https://mediere.anofm.ro/app/module/mediere/jobs)


[https://wpjobmanager.com/](https://wpjobmanager.com/) / [https://playground.wordpress.net/?plugin=wp-job-manager](https://playground.wordpress.net/?plugin=wp-job-manager) 

[Learn About Job Posting Schema Markup | Google Search Central | Documentation](https://developers.google.com/search/docs/appearance/structured-data/job-posting) 

### UI

- [Cariere.gov.md](https://cariere.gov.md/)   
- [https://findajob.dwp.gov.uk/](https://findajob.dwp.gov.uk/)   
- [https://www.google.com/about/careers/applications/jobs/results](https://www.google.com/about/careers/applications/jobs/results) 

## **Dev**

- use [https://schema.org/JobPosting](https://schema.org/JobPosting)   
- detect multiple jobs per posting  
- convert attachment to text.

- compare attachment to body text

- 

### Workflow

- get list of anunturi  
- get each anunț \+ attachment  
- based on attachment type get structured output

cat data/md/a828a1aa.md | llm 'generate schema.org/JobPosting json' \-m gpt-4o-mini \--option json\_object true \--no-stream \>\> data/schema-json/j3.json

\--key oai-gov2-1

anunțuri pentru mai multe posturi?

## **Prev Notes**

[https://github.com/peviitor-ro](https://github.com/peviitor-ro) 

\- \- \- \- \- \- \- \- \- \- \- \- \- \- \- \- \- \- \- \- \-  

1 [posturi.gov.ro](http://posturi.gov.ro/)  

\- doar cele comunicate pe email ?

2 scraper anunțuri angajare instituții publice

1. listă instituții publice  
2. get anunțuri page feed   
3. daily pings 

inform path from google site:xx anunt angajare|ocupare

get 1st list of instituții from [org.gov2.ro.docx](https://docs.google.com/document/d/1G0KZjzIiWI3fldba3gzySuypIEww3hnZ/) \+ google \- via api

get anunturi angajare kwds 

 

## **Custom**

[https://www.adr.gov.ro/cariera/](https://www.adr.gov.ro/cariera/) 

[https://www.adr.gov.ro/category-sitemap.xml](https://www.adr.gov.ro/category-sitemap.xml) 

[https://www.adr.gov.ro/sitemap\_index.xml](https://www.adr.gov.ro/sitemap_index.xml)

[https://github.com/MickaelWalter/wp-json-scraper](https://github.com/MickaelWalter/wp-json-scraper)  

