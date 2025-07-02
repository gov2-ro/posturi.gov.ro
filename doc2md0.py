import os
import sys
import subprocess
import docx2txt
import pypandoc
from pdf2image import convert_from_path
import pytesseract
import logging

# Set up logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

# Download pandoc if it's not already installed
try:
    pypandoc.get_pandoc_version()
except OSError:
    logging.info("Pandoc not found. Attempting to download...")
    pypandoc.download_pandoc()
    logging.info("Pandoc has been downloaded and installed.")

def convert_doc_to_markdown(file_path):
    logging.info(f"Converting DOC file: {file_path}")
    temp_docx = file_path + ".docx"
    try:
        subprocess.run(['soffice', '--headless', '--convert-to', 'docx', '--outdir', os.path.dirname(file_path), file_path], check=True)
        logging.info(f"DOC converted to DOCX: {temp_docx}")
        markdown = convert_docx_to_markdown(temp_docx)
        os.remove(temp_docx)
        return markdown
    except subprocess.CalledProcessError as e:
        logging.error(f"Error converting DOC to DOCX: {e}")
        raise

def convert_docx_to_markdown(file_path):
    logging.info(f"Converting DOCX file: {file_path}")
    try:
        # First attempt: using docx2txt and pypandoc
        text = docx2txt.process(file_path)
        markdown = pypandoc.convert_text(text, 'md', format='plain')
        logging.info("DOCX conversion completed using docx2txt and pypandoc")
        return markdown
    except Exception as e:
        logging.warning(f"First conversion method failed: {e}")
        try:
            # Second attempt: direct conversion with pypandoc
            markdown = pypandoc.convert_file(file_path, 'md')
            logging.info("DOCX conversion completed using direct pypandoc conversion")
            return markdown
        except Exception as e:
            logging.error(f"All conversion methods failed. Final error: {e}")
            raise

def convert_pdf_to_markdown(file_path):
    logging.info(f"Converting PDF file: {file_path}")
    try:
        images = convert_from_path(file_path)
        logging.info(f"PDF converted to {len(images)} images")
        text = ""
        for i, image in enumerate(images):
            logging.info(f"Performing OCR on page {i+1}")
            text += pytesseract.image_to_string(image)
        markdown = pypandoc.convert_text(text, 'md', format='plain')
        logging.info("PDF conversion completed")
        return markdown
    except Exception as e:
        logging.error(f"Error converting PDF to Markdown: {e}")
        raise

def convert_to_markdown(file_path):
    _, file_extension = os.path.splitext(file_path)
    file_extension = file_extension.lower()

    if file_extension == '.doc':
        return convert_doc_to_markdown(file_path)
    elif file_extension == '.docx':
        return convert_docx_to_markdown(file_path)
    elif file_extension == '.pdf':
        return convert_pdf_to_markdown(file_path)
    else:
        raise ValueError(f"Unsupported file format: {file_extension}")

def main():
    if len(sys.argv) != 2:
        print("Usage: python script.py <input_file>")
        sys.exit(1)

    input_file = sys.argv[1]
    try:
        logging.info(f"Starting conversion of {input_file}")
        markdown_content = convert_to_markdown(input_file)
        output_file = os.path.splitext(input_file)[0] + '.md'
        
        with open(output_file, 'w', encoding='utf-8') as f:
            f.write(markdown_content)
        
        logging.info(f"Conversion complete. Output saved to {output_file}")
        print(f"Conversion complete. Output saved to {output_file}")
    except Exception as e:
        logging.error(f"An error occurred: {str(e)}")
        print(f"An error occurred: {str(e)}")

if __name__ == "__main__":
    main()