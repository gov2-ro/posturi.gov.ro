import os
import google.generativeai as genai
from dotenv import load_dotenv

load_dotenv()
genai.configure(api_key=os.getenv("GOOGLE_API_KEY"))

file_uri = genai.upload_file(path='data/downloads/41d71860.docx')
prompt = "Convert the attached job posting to schema.org/JobPosting JSON-LD format. return the JSON-LD object."

# Send the prompt with the file URI
response = genai.generate_text(prompt = prompt, file_uri=file_uri)


print(response.text)