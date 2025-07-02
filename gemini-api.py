import google.generativeai as genai

genai.configure(api_key="AIzaSyBN3sL-00-0qzStkjoSXs6WZSmoxfpcPs0")

file_uri = genai.upload_file(path='data/downloads/41d71860.docx')
prompt = "Convert the attached job posting to schema.org/JobPosting JSON-LD format. return the JSON-LD object."

# Send the prompt with the file URI
response = genai.generate_text(prompt = prompt, file_uri=file_uri)


print(response.text)